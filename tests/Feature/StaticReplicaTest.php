<?php

use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    app(DestinationFactory::class)->register(customers_destination());
});

it('prepares typed replica attributes from its form', function (?string $destination) {
    config([
        'phpinnacle-ferry.origins.kafka.base_uri' => null,
        'phpinnacle-ferry.origins.kafka.signal.bootstrap_servers' => null,
    ]);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);

    $data = [
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => $destination === null ? DestinationType::Dynamic->value : DestinationType::Static->value,
        'destination' => $destination,
        'mapping' => $destination === null
            ? MappingState::binding(['_idrref' => 'external_id', '_description' => 'name', '_code' => 'tax_number'])
            : MappingState::mapping([
                'name' => MappingState::source('_description'),
                'tax_number' => MappingState::source('_code'),
                'customer_id' => MappingState::source('_idrref'),
            ]),
    ];

    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();

    $createForm = ReplicaForm::creation(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill();
    expect($createForm->getComponent('type')->getState())->toBe(DestinationType::Dynamic);

    $createForm->fill($data);
    $createState = $createForm->getState();

    expect($createState['destination'] ?? null)->toBe($destination);
    expect($createState['type'])->toBe($destination === null ? DestinationType::Dynamic : DestinationType::Static);

    $replica = Replica::create($createState);
    $form = TestCase::replicaForm([...$data, 'destination' => $replica->destination], $replica, $livewire);
    $createState = $form->getState();
    expect($form->getComponent('destination')->getOptionLabel())
        ->toBe($destination === null ? $replica->destination : 'Customers');
    $livewire->data['name'] = 'Renamed';

    $dehydrated = $form->getState();
    expect($dehydrated['mapping'])->toEqual($createState['mapping']);
    $replica->update($dehydrated);

    expect($replica->fresh()->name)
        ->toBe('Renamed')
        ->and($replica->fresh()->type)
        ->toBe($destination === null ? DestinationType::Dynamic : DestinationType::Static)
        ->and(array_column(
            array_filter($replica->fresh()->mapping, fn (FieldMapping $mapping) => $mapping->source !== null),
            'target',
            'source',
        ))
        ->toEqual([
            '_description' => 'name',
            '_code' => 'tax_number',
            '_idrref' => $destination === null ? 'external_id' : 'customer_id',
        ])
        ->and(Schema::hasTable('ferry_replica_customers_replica'))
        ->toBe($destination === null);

    if ($destination !== null) {
        expect($replica->fresh()->constantColumns())->toBe(['is_active']);
    }
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('keeps a static value through creating, filling and updating a static replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::mapping([
        'name' => MappingState::source('_description'),
        'is_active' => MappingState::value(true),
        'tax_number' => MappingState::value('none'),
        'customer_id' => MappingState::source('_idrref'),
    ], ['_description' => ['transforms' => []], '_idrref' => ['transforms' => []], '_code' => ['transforms' => []]]);

    $createForm = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => $state,
    ], livewire: $livewire);
    $createState = $createForm->getState();

    expect($createState['mapping'])
        ->toEqual($state);
    $state = $createState['mapping'];

    $replica = $createForm->getRecord();
    $replica->update($createState);

    expect(array_column($replica->mapping, 'source'))
        ->toBe(['_description', null, null, '_idrref', '_code'])
        ->and($replica->constantColumns())
        ->toBe(['is_active', 'tax_number'])
        ->and(app(ReplicaSchemaResolver::class)->outputSchema($replica, $replica->mapping[1])?->singleType())
        ->toBe('boolean');

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill($replica->fresh()->toArray());

    expect($form->getState()['mapping'])->toEqual($state);

    $form->getComponentByStatePath('mapping')->getStaticFormSchema('is_active')->getComponent('value')->state(false);
    $replica->update($form->getState());

    expect($replica->fresh()->mapping[1]->static)
        ->toBeFalse()
        ->and($replica->fresh()->mapping[1]->transforms())
        ->toBe([]);
});

it('keeps a typed static value through creating and filling a dynamic replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::binding([
        '_idrref' => 'external_id',
        '_description' => 'title',
        'priority' => ['value' => 5],
    ], [
        '_idrref' => ['transforms' => []],
        '_description' => ['transforms' => []],
    ]);

    $createForm = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => $state,
    ], livewire: $livewire);
    $createState = $createForm->getState();

    expect($createState['mapping'])
        ->toEqual($state);
    $state = $createState['mapping'];

    $replica = $createForm->getRecord();
    $replica->update($createState);

    expect(Schema::hasColumns('ferry_replica_customers_replica', ['title', 'priority']))
        ->toBeTrue()
        ->and(app(ReplicaSchemaResolver::class)->outputSchema($replica, $replica->mapping[2])?->singleType())
        ->toBe('integer');

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill($replica->fresh()->toArray());

    expect($form->getState()['mapping'])->toEqual($state);
});

it('keeps transforms through creating, filling and updating a replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
            '_folder' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();

    $createForm = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'is_active' => MappingState::source('_code'),
            'tax_number' => MappingState::value('none'),
            'customer_id' => MappingState::source('_idrref'),
        ], ['_code' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'boolean']]]]]),
    ], livewire: $livewire);
    $attributes = $createForm->getState();
    $replica = $createForm->getRecord();
    $replica->update($attributes);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $replica->mapping[1])?->singleType())
        ->toBe('boolean')
        ->and($replica->schema->properties()[$replica->mapping[1]->source]?->singleType())
        ->toBe('string')
        ->and($replica->mapping[1]->transforms())
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill($replica->fresh()->toArray());
    $state = $form->getState();

    expect($state)
        ->not
        ->toHaveKey('field_transforms')
        ->and($state['transforms'])
        ->toBe([])
        ->and(array_column($state['mapping'], null, 'source')['_code']['options']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'boolean']]]);

    $replica->update($state);

    expect($replica->fresh()->mapping[1]->transforms())
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);
});

it('offers Filter in the settings of an unbound source without a column selector', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_marked' => JsonSchema::fromArray(['type' => 'boolean'])]]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $key = $mapping->getSourceRowIndexes()['_marked'];
    $field = $form->getComponentByStatePath('mapping.' . $key . '.options.transforms');

    expect(array_keys($field->getAvailableBlocks()))->toBe([
        'cast',
        'filter',
        'boolean_to_string',
        'toggle',
    ]);
});

it('locks the destination and source on edit and preserves their mapping settings when renaming', function (
    string $lockedField,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_code' => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 11,
        'minLength' => 11,
    ])]]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => [],
    ]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $fieldTransforms = [['type' => 'cast', 'data' => ['type' => 'string']]];
    $form->fill([
        ...$replica->toArray(),
        'mapping' => MappingState::mapping([
            'name' => MappingState::source('_code'),
            'customer_id' => MappingState::source('_idrref'),
        ], [
            '_code' => ['transforms' => $fieldTransforms],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->isSourceUsed('_code'))->toBeTrue();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe($fieldTransforms);

    expect($form->getComponent($lockedField)->isDisabled())->toBeTrue();
    $form->getComponent('name')->state('Renamed')->callAfterStateUpdated();

    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->isSourceUsed('_code'))->toBeTrue();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe($fieldTransforms);
})->with([
    'destination' => ['destination'],
    'source' => ['source'],
    'upstream' => ['upstream_id'],
]);

it('validates transform parameters through their native form schemas', function (array $data) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => MappingState::binding(['_description' => 'title']),
        ...$data,
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
})->with([
    'unsupported cast target' => [[
        'mapping' => MappingState::binding(['_description' => 'title'], [
            '_description' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'reference']]]],
        ]),
    ]],
    'missing filter condition' => [[
        'mapping' => MappingState::binding(['_description' => 'title'], [
            '_description' => ['transforms' => [['type' => 'filter', 'data' => ['mode' => 'exclude']]]],
        ]),
    ]],
]);

it('allows repeated transforms on submission', function () {
    $settings = [
        ['type' => 'cast', 'data' => ['type' => 'int64']],
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'cast', 'data' => ['type' => 'int64']],
    ];
    $schema = FormSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components([
            \PHPinnacle\Ferry\Forms\FieldTransforms::make('transforms')->inputSchema(JsonSchema::fromArray([
                'type' => 'string',
            ])),
        ]);
    $schema->fill(['transforms' => $settings]);

    expect($schema->getState()['transforms'])->toBe($settings);
});

it('persists and restores transforms on every source independently of its target', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);
    $livewire = SchemaHost::make();

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Codes',
        'code' => 'codes_replica',
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => MappingState::binding(['_idrref' => 'external_id', '_code' => 'code']),
    ], livewire: $livewire);
    view()->share('errors', new ViewErrorBag);
    $mapping = $form->getComponentByStatePath('mapping');
    $keys = $mapping->getSourceRowIndexes();
    $action = $form->getComponentByStatePath('mapping.' . $keys['_code'] . '.options.transforms')->getAction(
        'fieldTransforms',
    );
    $cast = ['type' => 'cast', 'data' => ['type' => 'int64']];

    expect($form->toHtml())->toContain('data-binding-source-settings="_description"');
    $action(['block' => 'cast'])->data(['data' => $cast['data']])->call();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe([$cast]);
    $description = $form->getComponentByStatePath(
        'mapping.' . $keys['_description'] . '.options.transforms',
    )->getAction(
        'fieldTransforms',
    );
    $description(['block' => 'cast'])->data(['data' => $cast['data']])->call();
    expect($mapping->getSourceSettings()['_description']['transforms'])->toBe([$cast]);
    $attributes = $form->getState();

    expect($attributes['mapping'])
        ->toHaveCount(3)
        ->and($attributes['mapping'][2]['target'])
        ->toBeNull()
        ->and($attributes['mapping'][2]['options']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'int64']]])
        ->and($attributes['mapping'][1]['options']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'int64']]]);
});

it('validates destination columns directly for bindings and static values', function (array $bindings) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => MappingState::binding($bindings),
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
})->with([
    'empty mapping' => [[]],
    'invalid bound column' => [['_description' => 'Title']],
    'invalid constant column' => [['bad-column' => ['type' => 'string', 'value' => 'x']]],
]);

it('rejects a static value column that duplicates a bound column', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);
    $livewire = SchemaHost::make();

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
        'mapping' => MappingState::binding([
            '_description' => 'title',
            'title' => ['type' => 'string', 'value' => 'x'],
        ]),
    ], livewire: $livewire);

    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('rejects missing required static values before persistence', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);

    expect(fn () => TestCase::replicaFormState([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::value(null),
            'is_active' => MappingState::value(false),
            'customer_id' => MappingState::source('_idrref'),
        ], [
            '_idrref' => ['field' => TestCase::keySchema()->toArray()],
            '_description' => [
                'field' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])->toArray(),
                'result' => ['type' => 'string', 'maxLength' => 100],
                'transforms' => [],
            ],
        ]),
    ]))
        ->toThrow(ValidationException::class);
});

it('resolves the destination table from the registered static destination', function () {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'customer_directory',
        label: 'Customer directory',
        table: 'customers',
        primaryKey: ['customer_id'],
        schema: customers_destination()->getSchema(),
    ));
    $replica = TestCase::makeReplica([
        'code' => 'customers_replica',
        'type' => DestinationType::Static,
        'destination' => 'customer_directory',
        'mapping' => [
            new FieldMapping(source: '_description', target: 'name'),
            new FieldMapping(source: '_idrref', target: 'customer_id'),
        ],
    ]);

    config(['database.connections.sqlite.host' => 'localhost']);
    $connector = new SinkConfigFactory(app(TransformFactory::class), app(ReplicaSchemaResolver::class))->make(
        app(DestinationFactory::class)->resolve($replica),
        $replica,
        StructureItems::make([
            'fields' => [
                '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
                '_idrref' => TestCase::keySchema(),
            ],
            'sources' => [],
        ]),
        'topic',
    );

    expect($replica->type)
        ->toBe(DestinationType::Static)
        ->and($replica->destination)
        ->toBe('customer_directory')
        ->and($connector['table.name.format'])
        ->toBe('customers')
        ->and(Schema::hasTable('ferry_replica_customers_replica'))
        ->toBeFalse();
});

it('does not affect the destination table when a static replica is deleted', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
    });

    $replica = TestCase::makeReplica([
        'code' => 'customers_replica',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => [
            new FieldMapping(source: '_description', target: 'name'),
            new FieldMapping(source: '_idrref', target: 'customer_id'),
        ],
    ]);

    $replica->delete();

    expect(Schema::hasTable('customers'))
        ->toBeTrue()
        ->and(Replica::query()->count())
        ->toBe(0);
});

it('rejects editing when its destination is no longer registered', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeItem($upstream);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'customers_replica',
        'type' => DestinationType::Static,
        'destination' => 'customers',
    ]);
    $replica->forceFill(['type' => DestinationType::Static, 'destination' => 'gone'])->saveQuietly();
    expect(fn () => TestCase::replicaFormState([
        'schema' => $replica->fresh()->schema->toArray(),
        'name' => 'Renamed',
        'mapping' => [],
    ], $replica->fresh()))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', ['destination' => 'gone']),
        );
});

it('rejects an unregistered destination at the create form boundary', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);

    expect(fn () => TestCase::replicaCreationState([
        'upstream_id' => $upstream->id,
        'name' => 'Missing destination',
        'code' => 'missing',
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'missing',
        'mapping' => [],
    ]))
        ->toThrow(ValidationException::class);

    expect(Replica::query()->count())->toBe(0);
});

it('edits a Boolean constant through the built-in form without a cast', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
    ]]);
    $livewire = SchemaHost::make();

    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Static source',
        'code' => 'static_source',
        'source' => 'object-0',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::source('_code'),
            'customer_id' => MappingState::source('_idrref'),
        ]),
    ], livewire: $livewire);
    $mapping = $form->getComponentByStatePath('mapping');
    expect($mapping->hasStaticForms())->toBeTrue();
    $mapping->getStaticFormSchema('is_active')->getComponent('value')->state(false);
    $state = $form->getState();
    $constant = collect($state['mapping'])->firstWhere('kind', 'static');
    expect($constant)->toBe(['kind' => 'static', 'target' => 'is_active', 'static' => false, 'options' => []]);
    $replica = $form->getRecord();
    $replica->update($state);
    expect(
        app(ReplicaSchemaResolver::class)
            ->outputSchema($replica, collect($replica->mapping)->firstWhere('target', 'is_active'))
            ?->singleType(),
    )
        ->toBe('boolean');
    $edit = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $edit->fill($replica->fresh()->toArray());
    expect($edit->getState()['mapping'])->toEqual($state['mapping']);
});

it('creates an unmapped pending replica from only its identifying fields', function (DestinationType $type) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $form = ReplicaForm::creation(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'New replica',
        'code' => 'new_replica',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $type->value,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
    ]);

    expect(array_keys($form->getFlatFields()))->toBe([
        'name',
        'code',
        'upstream_id',
        'source',
        'type',
        ...($type === DestinationType::Static ? ['destination'] : []),
    ]);
    foreach (['upstream_id', 'source', ...($type === DestinationType::Static ? ['destination'] : [])] as $name) {
        $field = $form->getComponentByStatePath($name);
        expect($field->isNative())->toBeFalse()->and($field->isPreloaded())->toBeTrue();
    }

    $state = $form->getState();
    expect($state)->not->toHaveKey('schema');
    $replica = Replica::create(TestCase::replicaCreationState($state));

    expect($replica->fresh()->status)->toBe(ReplicaStatus::Pending);
    expect($replica->mapping)->toBe([]);
    expect($replica->connector_id)->toBeNull();
    expect($replica->type)->toBe($type);
    if ($type === DestinationType::Dynamic) {
        expect(Schema::hasTable($replica->destination))->toBeFalse();
    } else {
        expect(Schema::hasTable('customers'))->toBeFalse();
        $edit = ReplicaForm::configure(
            FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
        );
        $edit->fill($replica->toArray());
        expect($edit->getComponentByStatePath('mapping')->getTargetWarnings())->toBe([]);
        expect(fn () => TestCase::replicaFormState([
            'schema' => $replica->schema->toArray(),
            'name' => $replica->name,
            'mapping' => [],
        ], $replica))
            ->toThrow(ValidationException::class);
    }
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('preserves replica identity and schema when submitted edit data attempts to replace them', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $replica = Replica::create(TestCase::replicaCreationState([
        'name' => 'Original name',
        'code' => 'original_code',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => DestinationType::Dynamic->value,
    ]));
    $identity = $replica->only(['code', 'upstream_id', 'source', 'type', 'destination']);
    $snapshot = $replica->schema->toArray();
    $form = ReplicaForm::configure(
        FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill($replica->toArray());
    expect($form->getComponent('name')->isDisabled())->toBeFalse();
    foreach (['code', 'upstream_id', 'source', 'destination'] as $field) {
        expect($form->getComponent($field)->isDisabled())->toBeTrue();
    }

    expect($form->getComponent('schema', withHidden: true))->toBeNull();
    expect($form->getComponent('type', withHidden: true))->toBeNull();

    $state = TestCase::replicaFormState([
        'schema' => ['type' => 'object', 'properties' => []],
        'name' => 'Renamed',
        'mapping' => [TestCase::keyMapping()->jsonSerialize()],
        'code' => 'changed_code',
        'upstream_id' => 'another-upstream',
        'source' => 'another-object',
        'type' => DestinationType::Static->value,
        'destination' => 'customers',
    ], $replica);
    expect($state)->toHaveCount(3)->toHaveKeys(['name', 'mapping', 'transforms']);
    $replica->update($state);

    expect($replica->fresh()->name)->toBe('Renamed');
    expect($replica->fresh()->only(array_keys($identity)))->toBe($identity);
    expect($replica->fresh()->schema->toArray())->toBe($snapshot);
});
