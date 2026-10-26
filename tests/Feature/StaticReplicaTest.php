<?php

use Filament\Actions\Action;
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
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Resources\Replicas\Pages\EditReplica;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
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
        'destination' => $destination !== null ? 'static:' . $destination : DestinationType::Dynamic->value,
        'schema' => $destination === null
            ? MappingState::binding(['_description' => 'name', '_code' => 'tax_number'])
            : MappingState::mapping([
                'name' => MappingState::source('_description'),
                'tax_number' => MappingState::source('_code'),
            ]),
    ];

    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();

    $createForm = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill();
    expect($createForm->getComponent('destination')->getState())->toBe('dynamic');

    $createForm->fill($data);
    $createState = $createForm->getState();

    expect($createState['destination'])->toBe($destination === null ? 'dynamic' : 'static:' . $destination);
    expect($createState)->not->toHaveKey('type');

    $replica = Replica::create(TestCase::mappedReplicaAttributes(
        $createState,
        $destinations,
    ));
    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill(ReplicaForm::fill($replica->toArray()));
    expect($form->getComponent('destination')->getState())
        ->toBe($destination === null ? 'dynamic' : 'static:' . $destination);
    $livewire->data['name'] = 'Renamed';

    $dehydrated = $form->getState();
    expect($dehydrated['schema'])->toEqual($createState['schema']);
    $replica->update(ReplicaForm::forUpdate(
        $dehydrated,
        $replica,
        $destinations,
    ));

    expect($replica->fresh()->name)
        ->toBe('Renamed')
        ->and($replica->fresh()->type)
        ->toBe($destination === null ? DestinationType::Dynamic : DestinationType::Static)
        ->and(array_column(
            array_filter($replica->fresh()->schema, fn (FieldMapping $mapping) => $mapping->source !== null),
            'target',
            'source',
        ))
        ->toEqual(['_description' => 'name', '_code' => 'tax_number'])
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
    ], ['_description' => ['transforms' => []], '_code' => ['transforms' => []]]);

    $createForm = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => $state,
    ]);
    $createState = $createForm->getState();

    expect(array_map(fn (array $row) => array_diff_key($row, array_flip(['field', 'result'])), $createState['schema']))
        ->toEqual($state);
    $state = $createState['schema'];

    $replica = Replica::create(TestCase::mappedReplicaAttributes($createState, $destinations));

    expect(array_column($replica->schema, 'source'))
        ->toBe(['_description', null, null, '_code'])
        ->and($replica->constantColumns())
        ->toBe(['is_active', 'tax_number'])
        ->and($replica->schema[1]->outputSchema()?->singleType())
        ->toBe('boolean');

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill(ReplicaForm::fill($replica->fresh()->toArray()));

    expect($form->getState()['schema'])->toEqual($state);

    $form->getComponentByStatePath('schema')->getStaticFormSchema('is_active')->getComponent('value')->state(false);
    $replica->update(ReplicaForm::forUpdate(
        $form->getState(),
        $replica,
        $destinations,
    ));

    expect($replica->fresh()->schema[1]->static)
        ->toBeFalse()
        ->and($replica->fresh()->schema[1]->transforms)
        ->toBe([]);
});

it('keeps a typed static value through creating and filling a dynamic replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::binding(['_description' => 'title', 'priority' => ['value' => 5]], [
        '_description' => ['transforms' => []],
    ]);

    $createForm = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => $state,
    ]);
    $createState = $createForm->getState();

    expect(array_map(fn (array $row) => array_diff_key($row, array_flip(['field', 'result'])), $createState['schema']))
        ->toEqual($state);
    $state = $createState['schema'];

    $replica = Replica::create(TestCase::mappedReplicaAttributes($createState, $destinations));

    expect(Schema::hasColumns('ferry_replica_customers_replica', ['title', 'priority']))
        ->toBeTrue()
        ->and($replica->schema[1]->outputSchema()?->singleType())
        ->toBe('integer');

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill(ReplicaForm::fill($replica->fresh()->toArray()));

    expect($form->getState()['schema'])->toEqual($state);
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

    $createForm = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'is_active' => MappingState::source('_code'),
            'tax_number' => MappingState::value('none'),
        ], ['_code' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'boolean']]]]]),
    ]);
    $attributes = TestCase::mappedReplicaAttributes(
        $createForm->getState(),
        $destinations,
    );
    $replica = Replica::create($attributes);

    expect($replica->schema[1]->outputSchema()?->singleType())
        ->toBe('boolean')
        ->and($replica->schema[1]->sourceSchema()?->singleType())
        ->toBe('string')
        ->and($replica->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $form->fill(ReplicaForm::fill($replica->fresh()->toArray()));
    $state = $form->getState();

    expect($state)
        ->not
        ->toHaveKeys(['transforms', 'field_transforms'])
        ->and(array_column($state['schema'], null, 'source')['_code']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'boolean']]]);

    $replica->update(ReplicaForm::forUpdate($state, $replica, $destinations));

    expect($replica->fresh()->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);
});

it('offers Filter in the settings of an unbound source without a column selector', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_marked' => JsonSchema::fromArray(['type' => 'boolean'])]]);
    $form = ReplicaForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill(['upstream_id' => $upstream->id, 'source' => 'object-0', 'destination' => 'dynamic']);
    $mapping = $form->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_marked'];
    $field = $form->getComponentByStatePath('schema.sources.' . $key . '.transforms');

    expect(array_keys($field->getAvailableTransforms(app(TransformRegistry::class))))->toBe(['cast', 'filter']);
});

it('resets source settings with mappings when the destination or source changes', function (
    string $changed,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => ['_code' => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 11,
        'minLength' => 11,
    ])]]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $fieldTransforms = [['type' => 'cast', 'data' => ['type' => 'string']]];
    $form->fill([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping(['name' => MappingState::source('_code')], [
            '_code' => ['transforms' => $fieldTransforms],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->isSourceUsed('_code'))->toBeTrue();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe([[
        'type' => 'cast',
        'data' => ['type' => 'string', 'replace_null_with_default' => 'true'],
    ]]);

    $form
        ->getComponent($changed)
        ->state($changed === 'destination' ? 'dynamic' : null)
        ->callAfterStateUpdated();

    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->isSourceUsed('_code'))->toBeFalse();
    expect(array_filter(array_map(
        fn (array $settings) => $settings['transforms'],
        $mapping->getSourceSettings(),
    )))->toBe([]);
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
    $form = ReplicaForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => MappingState::binding(['_description' => 'title']),
        ...$data,
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
})->with([
    'unsupported cast target' => [[
        'schema' => MappingState::binding(['_description' => 'title'], [
            '_description' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'reference']]]],
        ]),
    ]],
    'missing filter condition' => [[
        'schema' => MappingState::binding(['_description' => 'title'], [
            '_description' => ['transforms' => [['type' => 'filter', 'data' => ['mode' => 'exclude']]]],
        ]),
    ]],
]);

it('allows repeated transforms on submission', function () {
    $settings = [
        ['type' => 'cast', 'data' => ['type' => 'integer']],
        ['type' => 'cast', 'data' => ['type' => 'string']],
        ['type' => 'cast', 'data' => ['type' => 'integer']],
    ];
    $schema = FormSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components([
            \PHPinnacle\Ferry\Forms\FieldTransforms::make('transforms')->inputSchema(['type' => 'string']),
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

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Codes',
        'code' => 'codes_replica',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => MappingState::binding(['_code' => 'code']),
    ]);
    view()->share('errors', new ViewErrorBag);
    $mapping = $form->getComponentByStatePath('schema');
    $keys = $mapping->getSourceStateKeys();
    $action = $form->getComponentByStatePath('schema.sources.' . $keys['_code'] . '.transforms')->getAction(
        'fieldTransforms',
    );
    $cast = ['type' => 'cast', 'data' => ['type' => 'integer']];
    $hydratedCast = ['type' => 'cast', 'data' => ['type' => 'integer', 'replace_null_with_default' => 'true']];

    expect($form->toHtml())->toContain('data-binding-source-settings="_description"');
    $action(['block' => 'cast'])->data(['data' => $cast['data']])->call();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe([$hydratedCast]);
    $description = $form->getComponentByStatePath('schema.sources.' . $keys['_description'] . '.transforms')->getAction(
        'fieldTransforms',
    );
    $description(['block' => 'cast'])->data(['data' => $cast['data']])->call();
    expect($mapping->getSourceSettings()['_description']['transforms'])->toBe([$hydratedCast]);
    $attributes = TestCase::mappedReplicaAttributes(
        $form->getState(),
        app(DestinationFactory::class),
    );

    expect($attributes['schema'])
        ->toHaveCount(2)
        ->and($attributes['schema'][1]['target'])
        ->toBeNull()
        ->and($attributes['schema'][1]['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'integer']]])
        ->and($attributes['schema'][0]['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'integer']]]);
});

it('validates destination columns directly for bindings and static values', function (array $bindings) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);
    $form = ReplicaForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => MappingState::binding($bindings),
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
})->with([
    'empty mapping' => [[]],
    'invalid bound column' => [['_description' => 'Title']],
    'reserved bound column' => [['_description' => Replica::ID_COLUMN]],
    'invalid constant column' => [['bad-column' => ['type' => 'string', 'value' => 'x']]],
    'reserved constant column' => [[Replica::ID_COLUMN => ['type' => 'string', 'value' => 'x']]],
]);

it('rejects a static value column that duplicates a bound column', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);
    $livewire = SchemaHost::make();

    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Customers',
        'code' => 'customers_replica',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => MappingState::binding([
            '_description' => 'title',
            'title' => ['type' => 'string', 'value' => 'x'],
        ]),
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('rejects unsupported static values before persistence', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
    ]);

    expect(fn () => TestCase::mappedReplicaAttributes(
        [
            'upstream_id' => $upstream->id,
            'name' => 'Customers',
            'code' => 'customers_replica',
            'source' => 'object-0',
            'destination' => 'static:customers',
            'schema' => MappingState::mapping([
                'name' => MappingState::source('_description'),
                'tax_number' => MappingState::value('x'),
                'is_active' => MappingState::value('maybe'),
            ], [
                '_description' => [
                    'field' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])->toArray(),
                    'result' => ['type' => 'string', 'maxLength' => 100],
                    'transforms' => [],
                ],
            ]),
        ],
        app(DestinationFactory::class),
    ))
        ->toThrow(ValidationException::class);
});

it('confirms removed and retyped dynamic columns when saving the form', function (string $change, array $columns) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'fields' => [
            '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
            '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $change === 'static' ? DestinationType::Static : DestinationType::Dynamic,
        'destination' => $change === 'static' ? 'customers' : null,
        'schema' => [
            new FieldMapping('_description', 'name', $object->field('_description')),
            new FieldMapping('_code', 'tax_number', $object->field('_code')),
        ],
    ]);

    if ($change === 'retyped') {
        $schema = $object->schema->toArray();
        $schema['properties']['_code']['type'] = 'boolean';
        unset($schema['properties']['_code']['minLength'], $schema['properties']['_code']['maxLength']);
        $object->update(['schema' => JsonSchema::fromArray($schema)]);
    }

    $page = new class extends EditReplica {
        public function saveAction(): Action
        {
            return $this->getSaveFormAction()->livewire($this);
        }
    };
    $page->setId('replica-drop-confirmation');
    $page->setName('replica-drop-confirmation');
    $page->record = $replica;
    $this->app->call([$page, 'boot']);
    $page->form->fill(ReplicaForm::fill($replica->toArray()));

    if ($change === 'removed') {
        $field = $page->form->getComponentByStatePath('schema');
        $field->getAction('unbind')(['source' => '_description'])->call();
    }

    if ($change === 'renamed') {
        $field = $page->form->getComponentByStatePath('schema');
        $page->data['schema']['bindings'][$field->getBindingKey('_description')]['target'] = 'display_name';
    }

    $action = $page->saveAction();
    $action->mount(['schema' => null]);

    expect($action->shouldOpenModal())
        ->toBe($columns !== [])
        ->and($page->record->isDirty())
        ->toBeFalse();

    if ($columns !== []) {
        expect($action->getModalDescription())->toBe(__(
            'phpinnacle-ferry::resources.replica.modals.drop_columns.description',
            ['columns' => implode(', ', $columns)],
        ));
    }
})->with([
    'unchanged mapping' => ['unchanged', []],
    'removed field' => ['removed', ['name']],
    'retyped field' => ['retyped', ['tax_number']],
    'renamed column' => ['renamed', []],
    'static destination' => ['static', []],
]);

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
        'schema' => [
            new FieldMapping('_description', 'name', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ]);

    config(['database.connections.sqlite.host' => 'localhost']);
    $connector = new SinkConfigFactory(app(TransformFactory::class))->make(
        app(DestinationFactory::class)->resolve($replica),
        $replica,
        StructureItems::make([
            'fields' => ['_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])],
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
        'schema' => [
            new FieldMapping('_description', 'name', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ]);

    $replica->delete();

    expect(Schema::hasTable('customers'))
        ->toBeTrue()
        ->and(Replica::query()->count())
        ->toBe(0);
});

it('pauses a replica and rejects editing when its destination is no longer registered', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeItem($upstream);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'customers_replica',
        'type' => DestinationType::Static,
        'destination' => 'customers',
    ]);
    $replica->forceFill(['type' => DestinationType::Static, 'destination' => 'gone'])->saveQuietly();
    $upstream->structure->items()->update(['revision' => $upstream->structure->draftRevision()]);
    $upstream->structure->publish();

    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Pause);

    expect(fn () => ReplicaForm::forUpdate(
        ['name' => 'Renamed', 'schema' => []],
        $replica->fresh(),
        app(DestinationFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', ['destination' => 'gone']),
        );
});

it('rejects an unregistered destination at the create form boundary', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);

    expect(fn () => TestCase::mappedReplicaAttributes([
        'upstream_id' => $upstream->id,
        'name' => 'Missing destination',
        'code' => 'missing',
        'source' => 'object-0',
        'destination' => 'static:missing',
        'schema' => [],
    ], app(DestinationFactory::class)))
        ->toThrow(LogicException::class);

    expect(Replica::query()->count())->toBe(0);
});

it('edits a Boolean constant through the built-in form without a cast', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
    ]]);
    $livewire = SchemaHost::make();
    $form = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'upstream_id' => $upstream->id,
        'name' => 'Static source',
        'code' => 'static_source',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::source('_code'),
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->hasStaticForms())->toBeTrue();
    expect($mapping->hasStaticValues())->toBeFalse();
    $mapping->getStaticFormSchema('is_active')->getComponent('value')->state(false);
    $state = $form->getState();
    $constant = collect($state['schema'])->firstWhere('kind', 'static');
    expect($constant)->toBe([
        'kind' => 'static',
        'target' => 'is_active',
        'static' => false,
    ]);
    $replica = Replica::create(TestCase::mappedReplicaAttributes($state, app(DestinationFactory::class)));
    expect(collect($replica->schema)->firstWhere('target', 'is_active')->outputSchema()?->singleType())
        ->toBe('boolean');
    $edit = ReplicaForm::configure(FormSchema::make($livewire)->statePath('data')->record($replica)->operation('edit'));
    $edit->fill(ReplicaForm::fill($replica->fresh()->toArray()));
    expect($edit->getState()['schema'])->toEqual($state['schema']);
});

it('preserves existing transformed constants when editing a static replica', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, ['fields' => [
        '_description' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]),
        '_code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11, 'minLength' => 11]),
    ]]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
            new FieldMapping('_code', 'tax_number', JsonSchema::fromArray([
                'type' => 'string',
                'maxLength' => 11,
                'minLength' => 11,
            ])),
            new FieldMapping(
                null,
                'is_active',
                null,
                result: JsonSchema::fromArray(['type' => 'boolean']),
                transforms: [new TransformSetting('cast', ['type' => 'boolean'])],
                static: 'false',
                kind: FieldKind::Static,
            ),
        ],
    ]);
    $original = $replica->schema[2];
    $form = ReplicaForm::configure(
        FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill(ReplicaForm::fill($replica->toArray()));

    $replica->update(ReplicaForm::forUpdate($form->getState(), $replica, app(DestinationFactory::class)));

    expect($form->getComponentByStatePath('schema')->hasStaticValues())->toBeTrue();
    expect(collect($replica->fresh()->schema)->firstWhere('kind', FieldKind::Static))->toEqual($original);
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
        'destination' => $type === DestinationType::Static ? 'static:customers' : 'dynamic',
    ]);

    expect(array_keys($form->getFlatFields()))->toBe(['name', 'code', 'upstream_id', 'source', 'destination']);
    $state = $form->getState();
    expect($state)->not->toHaveKey('schema');
    $replica = Replica::create(ReplicaForm::forCreate($state, app(DestinationFactory::class)));

    expect($replica->fresh()->status)->toBe(ReplicaStatus::Pending);
    expect($replica->schema)->toBe([]);
    expect($replica->connector_id)->toBeNull();
    expect($replica->type)->toBe($type);
    if ($type === DestinationType::Dynamic) {
        expect(Schema::getColumnListing($replica->destination))->toBe([Replica::ID_COLUMN]);
    } else {
        expect(Schema::hasTable('customers'))->toBeFalse();
        $edit = ReplicaForm::configure(
            FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
        );
        $edit->fill(ReplicaForm::fill($replica->toArray()));
        expect($edit->getComponentByStatePath('schema')->getTargetWarnings())->toBe([]);
        expect(fn () => ReplicaForm::forUpdate(
            ['name' => $replica->name, 'schema' => []],
            $replica,
            app(DestinationFactory::class),
        ))
            ->toThrow(ValidationException::class);
    }
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('locks identifying fields on edit and ignores attempts to change them through submitted data', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);
    $replica = Replica::create(ReplicaForm::forCreate([
        'name' => 'Original name',
        'code' => 'original_code',
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'destination' => 'dynamic',
    ], app(DestinationFactory::class)));
    $identity = $replica->only(['code', 'upstream_id', 'source', 'type', 'destination']);
    $form = ReplicaForm::configure(
        FormSchema::make(SchemaHost::make())->statePath('data')->record($replica)->operation('edit'),
    );
    $form->fill(ReplicaForm::fill($replica->toArray()));
    expect($form->getComponent('name')->isDisabled())->toBeFalse();
    foreach (['code', 'upstream_id', 'source', 'destination'] as $field) {
        expect($form->getComponent($field)->isDisabled())->toBeTrue();
    }

    $replica->update(ReplicaForm::forUpdate(
        [
            'name' => 'Renamed',
            'schema' => [],
            'code' => 'changed_code',
            'upstream_id' => 'another-upstream',
            'source' => 'another-object',
            'type' => DestinationType::Static,
            'destination' => 'static:customers',
        ],
        $replica,
        app(DestinationFactory::class),
    ));

    expect($replica->fresh()->name)->toBe('Renamed');
    expect($replica->fresh()->only(array_keys($identity)))->toBe($identity);
});
