<?php

use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

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
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
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

    $livewire = SchemaHost::make();

    $createForm = ReplicaForm::creation(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill($data);
    $createState = $createForm->getState();

    $replica = Replica::create($createState);
    $form = TestCase::replicaForm([...$data, 'destination' => $replica->destination], $replica, $livewire);
    $createState = $form->getState();
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
        ->and(DatabaseSchema::hasTable('ferry_replica_customers_replica'))
        ->toBe($destination === null);

    if ($destination !== null) {
        expect($replica->fresh()->constantColumns())->toBe(['is_active']);
    }
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('keeps a static value through creating, filling and updating a static replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        ],
    ]);
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

it('keeps transforms through creating, filling and updating a replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
            '_folder' => Schema::fromArray(['type' => 'boolean']),
        ],
    ]);
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

it('validates transform parameters on a replica with a complete key mapping', function (
    array $settings,
    string $parameter,
    array $valid,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'mapping' => MappingState::binding(['_idrref' => 'external_id', '_description' => 'title'], [
            '_description' => ['transforms' => $settings],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('mapping');
    $path = 'mapping.' . $mapping->getSourceRowIndex('_description') . '.options.transforms';

    expect($form->getState(...))->toThrow(function (ValidationException $exception) use ($path, $parameter) {
        expect(array_keys($exception->errors()))->toContain('data.' . $path . '.0.data.' . $parameter);
    });

    $form->getComponentByStatePath($path)->state($valid);
    expect(collect($form->getState()['mapping'])->firstWhere('source', '_description')['options']['transforms'])
        ->toBe($valid);
})->with([
    'unsupported cast target' => [
        [['type' => 'cast', 'data' => ['type' => 'reference']]],
        'type',
        [['type' => 'cast', 'data' => ['type' => 'int64']]],
    ],
    'missing filter condition' => [
        [['type' => 'filter', 'data' => ['mode' => 'exclude', 'missing_or_null_behavior' => 'include']]],
        'condition',
        [[
            'type' => 'filter',
            'data' => ['mode' => 'exclude', 'condition' => '@ != null', 'missing_or_null_behavior' => 'include'],
        ]],
    ],
]);

it('persists and restores transforms on every source independently of its target', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
            '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
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

    $replica = $form->getRecord();
    $replica->update($attributes);
    $edit = TestCase::replicaForm([], $replica->fresh());
    expect($edit->getState()['mapping'])->toEqual($attributes['mapping']);
    expect(collect($replica->fresh()->mapping)->firstWhere('source', '_description')->target)->toBeNull();
});

it('validates destination column names with every source key mapped', function (array $bindings, string $column) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'mapping' => MappingState::binding(['_idrref' => 'external_id', ...$bindings]),
    ]);

    expect($form->getState(...))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::validation.replica_schema.column_format', [
            'column' => $column,
        ]));
})->with([
    'bound column' => [['_description' => 'Title'], 'Title'],
    'constant column' => [['bad-column' => ['value' => 'x']], 'bad-column'],
]);

it('rejects an empty replica mapping at the form boundary', function () {
    $form = TestCase::replicaForm(['mapping' => []]);

    expect($form->getState(...))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::validation.replica_schema.empty'));
});

it('rejects a static value column that duplicates a bound column', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'mapping' => MappingState::binding([
            '_idrref' => 'external_id',
            '_description' => 'title',
            'title' => ['value' => 'x'],
        ]),
    ]);

    expect($form->getState(...))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::validation.replica_schema.column_duplicate', [
            'column' => 'title',
        ]));
});

it('rejects a missing required static value at its native field', function () {
    $form = TestCase::replicaForm([
        'name' => 'Customers',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::value('Customer'),
            'tax_number' => MappingState::value(null),
            'is_active' => MappingState::value(false),
        ]),
    ]);
    $value = $form->getComponentByStatePath('mapping')->getStaticFormSchema('tax_number')->getComponent('value');

    expect($form->getState(...))->toThrow(function (ValidationException $exception) use ($value) {
        expect($exception->errors())->toHaveKey($value->getStatePath());
    });

    $value->state('123');
    expect(collect($form->getState()['mapping'])->firstWhere('target', 'tax_number')['static'])->toBe('123');
});

it('resolves the destination table from the registered static destination', function () {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'customer_directory',
        label: 'Customer directory',
        table: 'customers',
        primaryKey: ['customer_id'],
        schema: customers_destination()->getSchema(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'type' => DestinationType::Static,
        'destination' => 'customer_directory',
        'mapping' => MappingState::mapping([
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::value('none'),
        ]),
    ]);
    $replica = $form->getRecord();
    $replica->update($form->getState());
    config(['database.connections.sqlite.host' => 'localhost']);

    $connector = app(SinkConfigFactory::class)->make(
        app(DestinationFactory::class)->resolve($replica),
        $replica,
        $replica->sourceObject(),
        'topic',
    );

    expect($replica->fresh()->destination)->toBe('customer_directory');
    expect($connector['table.name.format'])->toBe('customers');
    expect(DatabaseSchema::hasTable('ferry_replica_' . $replica->code))->toBeFalse();
});

it('preserves the static table and its rows after the deletion job completes', function () {
    DatabaseSchema::create('customers', function (Blueprint $table) {
        $table->uuid('customer_id')->primary();
        $table->string('name');
        $table->string('tax_number');
    });
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_description' => Schema::fromArray([
        'type' => 'string',
        'maxLength' => 100,
    ])]]);
    $form = TestCase::replicaForm([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::value('none'),
        ]),
    ]);
    $replica = $form->getRecord();
    $replica->update($form->getState());
    $row = [
        'customer_id' => '12345678-1234-1234-1234-123456789abc',
        'name' => 'Existing customer',
        'tax_number' => '123',
    ];
    $replica->getConnection()->table('customers')->insert($row);

    $replica->delete();
    new \PHPinnacle\Ferry\Jobs\Connector\SynchronizeConnectorsJob($upstream->id)->handle(app(\PHPinnacle\Ferry\Services\ConnectorManager::class));

    expect(Replica::withTrashed()->whereKey($replica->id)->exists())->toBeFalse();
    expect(DatabaseSchema::hasTable('customers'))->toBeTrue();
    expect((array) $replica->getConnection()->table('customers')->sole())->toBe($row);
});

it('rejects editing when its previously registered destination is removed', function () {
    $form = TestCase::replicaForm([
        'name' => 'Customers',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => MappingState::mapping([
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::value('Customer'),
            'tax_number' => MappingState::value('123'),
        ]),
    ]);
    $replica = $form->getRecord();
    $replica->update($form->getState());
    app()->instance(DestinationFactory::class, new DestinationFactory(app(ReplicaSchemaResolver::class)));

    expect(fn () => TestCase::replicaFormState(['name' => 'Renamed', 'mapping' => []], $replica->fresh()))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', [
            'destination' => 'customers',
        ]));
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
        '_description' => Schema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => Schema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
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

    $state = $form->getState();
    expect($state)->not->toHaveKey('schema');
    $replica = Replica::create($state);

    expect($replica->fresh()->status)->toBe(ReplicaStatus::Pending);
    expect($replica->mapping)->toBe([]);
    expect($replica->connector_id)->toBeNull();
    expect($replica->type)->toBe($type);
    if ($type === DestinationType::Dynamic) {
        expect(DatabaseSchema::hasTable($replica->destination))->toBeFalse();
    } else {
        expect(DatabaseSchema::hasTable('customers'))->toBeFalse();
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

it('preserves replica identity and schema when submitted edit data attempts to replace them', function (DestinationType $type) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $replica = Replica::create(TestCase::replicaCreationState([
        'name' => 'Original name',
        'code' => 'original_code',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'type' => $type->value,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
    ]));
    $identity = $replica->only(['code', 'upstream_id', 'source', 'type', 'destination']);
    $snapshot = $replica->schema->toArray();
    $form = ReplicaForm::configure(
        FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill($replica->toArray());
    foreach (['code', 'upstream_id', 'source', 'destination'] as $field) {
        expect($form->getComponent($field)->isDisabled())->toBeTrue();
    }

    expect($form->getComponent('destination')->getOptionLabel())
        ->toBe($type === DestinationType::Static ? 'Customers' : $replica->destination);
    $mapping = $type === DestinationType::Dynamic
        ? [TestCase::keyMapping()->jsonSerialize()]
        : MappingState::mapping([
            'customer_id' => MappingState::source('_idrref'),
            'name' => MappingState::value('Customer'),
            'tax_number' => MappingState::value('123'),
        ]);

    $state = TestCase::replicaFormState([
        'schema' => ['type' => 'object', 'properties' => []],
        'name' => 'Renamed',
        'mapping' => $mapping,
        'code' => 'changed_code',
        'upstream_id' => 'another-upstream',
        'source' => 'another-object',
        'type' => $type === DestinationType::Dynamic ? DestinationType::Static->value : DestinationType::Dynamic->value,
        'destination' => 'hijacked_table',
    ], $replica);
    expect($state)->toHaveCount(3)->toHaveKeys(['name', 'mapping', 'transforms']);
    $replica->update($state);

    expect($replica->fresh()->name)->toBe('Renamed');
    expect($replica->fresh()->only(array_keys($identity)))->toBe($identity);
    expect($replica->fresh()->schema->toArray())->toBe($snapshot);
})->with([DestinationType::Dynamic, DestinationType::Static]);
