<?php

use Filament\Actions\Action;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

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

it('prepares typed synchronization attributes from its form', function (?string $destination) {
    config([
        'phpinnacle-ferry.kafka_connect.base_uri' => null,
        'phpinnacle-ferry.kafka_connect.signal.bootstrap_servers' => null,
    ]);
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);

    $data = [
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
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

    $createForm = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill();
    expect($createForm->getComponent('destination')->getState())->toBe('dynamic');

    $createForm->fill($data);
    $createState = $createForm->getState();

    expect($createState['destination'])->toBe($destination === null ? 'dynamic' : 'static:' . $destination);
    expect($createState)->not->toHaveKey('type');

    $sync = Sync::create(SyncForm::forCreate(
        $createState,
        $destinations,
    ));
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->toArray()));
    expect($form->getComponent('destination')->getState())
        ->toBe($destination === null ? 'dynamic' : 'static:' . $destination);
    $livewire->data['name'] = 'Renamed';

    $dehydrated = $form->getState();
    expect($dehydrated['schema'])->toEqual($createState['schema']);
    $sync->update(SyncForm::forUpdate(
        $dehydrated,
        $sync,
        $destinations,
    ));

    expect($sync->fresh()->name)
        ->toBe('Renamed')
        ->and($sync->fresh()->type)
        ->toBe($destination === null ? DestinationType::Dynamic : DestinationType::Static)
        ->and(array_column(
            array_filter($sync->fresh()->schema, fn (FieldMapping $mapping) => $mapping->source !== null),
            'target',
            'source',
        ))
        ->toEqual(['_description' => 'name', '_code' => 'tax_number'])
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBe($destination === null);

    if ($destination !== null) {
        expect($sync->fresh()->constantColumns())->toBe([]);
    }
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('keeps a static value through creating, filling and updating a static synchronization', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::mapping([
        'name' => MappingState::source('_description'),
        'is_active' => MappingState::value(true),
        'tax_number' => MappingState::value('none'),
    ], ['_description' => ['transforms' => []], '_code' => ['transforms' => []]]);

    $createForm = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => $state,
    ]);
    $createState = $createForm->getState();

    expect(array_map(fn (array $row) => array_diff_key($row, array_flip(['field', 'result'])), $createState['schema']))
        ->toEqual($state);
    $state = $createState['schema'];

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations));

    expect(array_column($sync->schema, 'source'))
        ->toBe(['_description', null, null, '_code'])
        ->and($sync->constantColumns())
        ->toBe(['is_active', 'tax_number'])
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Boolean);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getState()['schema'])->toEqual($state);

    $livewire->data['schema']['constants']['static_1']['static'] = false;
    $sync->update(SyncForm::forUpdate(
        $form->getState(),
        $sync,
        $destinations,
    ));

    expect($sync->fresh()->schema[1]->static)
        ->toBeFalse()
        ->and($sync->fresh()->schema[1]->transforms)
        ->toBe([]);
});

it('keeps a typed static value through creating and filling a dynamic synchronization', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::binding(['_description' => 'title', 'priority' => ['value' => 5]], [
        '_description' => ['transforms' => []],
    ]);

    $createForm = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => $state,
    ]);
    $createState = $createForm->getState();

    expect(array_map(fn (array $row) => array_diff_key($row, array_flip(['field', 'result'])), $createState['schema']))
        ->toEqual($state);
    $state = $createState['schema'];

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations));

    expect(Schema::hasColumns('ferry_sync_customers_sync', ['title', 'priority']))
        ->toBeTrue()
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Integer);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getState()['schema'])->toEqual($state);
});

it('keeps transforms through creating, filling and updating a synchronization', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
            '_folder' => new ScalarField(FieldType::Boolean),
        ],
    ]);
    $destinations = app(DestinationFactory::class);
    $livewire = SchemaHost::make();

    $createForm = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping([
            'name' => MappingState::source('_description'),
            'is_active' => MappingState::source('_code'),
            'tax_number' => MappingState::value('none'),
        ], ['_code' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'boolean']]]]]),
    ]);
    $attributes = SyncForm::forCreate(
        $createForm->getState(),
        $destinations,
    );
    $sync = Sync::create($attributes);

    expect($sync->schema[1]->type())
        ->toBe(ColumnType::Boolean)
        ->and($sync->schema[1]->sourceType())
        ->toBe(ColumnType::String)
        ->and($sync->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));
    $state = $form->getState();

    expect($state)
        ->not
        ->toHaveKeys(['transforms', 'field_transforms'])
        ->and(array_column($state['schema'], null, 'source')['_code']['transforms'])
        ->toBe([['type' => 'cast', 'data' => ['type' => 'boolean']]]);

    $sync->update(SyncForm::forUpdate($state, $sync, $destinations));

    expect($sync->fresh()->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])]);
});

it('offers Filter in the settings of an unbound source without a column selector', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => ['_marked' => new ScalarField(FieldType::Boolean)]]);
    $form = SyncForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill(['connection_id' => $connection->id, 'source' => 'object-0', 'destination' => 'dynamic']);
    $mapping = $form->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_marked'];
    $field = $form->getComponentByStatePath('schema.sources.' . $key . '.transforms');

    expect(array_keys($field->getAvailableTransforms(app(TransformRegistry::class))))->toBe(['cast', 'filter']);
});

it('resets source settings with mappings when the destination or source changes', function (
    string $changed,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => ['_code' => new StringField(length: 11, fixed: true)]]);
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $fieldTransforms = [['type' => 'cast', 'data' => ['type' => 'string']]];
    $form->fill([
        'connection_id' => $connection->id,
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
    'connection' => ['connection_id'],
]);

it('validates transform parameters through their native form schemas', function (array $data) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);
    $form = SyncForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
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
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);
    $livewire = SchemaHost::make();

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Codes',
        'code' => 'codes_sync',
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
    $attributes = SyncForm::forCreate(
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
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);
    $form = SyncForm::configure(FormSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => MappingState::binding($bindings),
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
})->with([
    'empty mapping' => [[]],
    'invalid bound column' => [['_description' => 'Title']],
    'reserved bound column' => [['_description' => Sync::ID_COLUMN]],
    'invalid constant column' => [['bad-column' => ['type' => 'string', 'value' => 'x']]],
    'reserved constant column' => [[Sync::ID_COLUMN => ['type' => 'string', 'value' => 'x']]],
]);

it('rejects a static value column that duplicates a bound column', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);
    $livewire = SchemaHost::make();

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
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
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);

    expect(fn () => SyncForm::forCreate(
        [
            'connection_id' => $connection->id,
            'name' => 'Customers',
            'code' => 'customers_sync',
            'source' => 'object-0',
            'destination' => 'static:customers',
            'schema' => MappingState::mapping([
                'name' => MappingState::source('_description'),
                'tax_number' => MappingState::value('x'),
                'is_active' => MappingState::value('maybe'),
            ], [
                '_description' => [
                    'field' => new StringField(length: 100, fixed: false)->jsonSerialize(),
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
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'system' => [
            '_description' => new StringField(length: 100, fixed: false),
            '_code' => new StringField(length: 11, fixed: true),
        ],
    ]);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'type' => $change === 'static' ? DestinationType::Static : DestinationType::Dynamic,
        'destination' => $change === 'static' ? 'customers' : null,
        'schema' => [
            new FieldMapping('_description', 'name', $object->field('_description')),
            new FieldMapping('_code', 'tax_number', $object->field('_code')),
        ],
    ]);

    if ($change === 'retyped') {
        $object->update(['system' => [
            '_description' => new StringField(length: 100, fixed: false),
            '_code' => new ScalarField(FieldType::Boolean),
        ]]);
    }

    $page = new class extends EditSync {
        public function saveAction(): Action
        {
            return $this->getSaveFormAction()->livewire($this);
        }
    };
    $page->setId('sync-drop-confirmation');
    $page->setName('sync-drop-confirmation');
    $page->record = $sync;
    $this->app->call([$page, 'boot']);
    $page->form->fill(SyncForm::fill($sync->toArray()));

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
            'phpinnacle-ferry::resources.sync.modals.drop_columns.description',
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
        primaryKey: 'customer_id',
        fields: customers_destination()->fields,
    ));
    $sync = TestCase::makeSync([
        'code' => 'customers_sync',
        'type' => DestinationType::Static,
        'destination' => 'customer_directory',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
        ],
    ]);

    expect($sync->type)
        ->toBe(DestinationType::Static)
        ->and($sync->destination)
        ->toBe('customer_directory')
        ->and(app(DestinationFactory::class)->resolve($sync)->table)
        ->toBe('customers')
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBeFalse();
});

it('does not affect the destination table when a static synchronization is deleted', function () {
    Schema::create('customers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
    });

    $sync = TestCase::makeSync([
        'code' => 'customers_sync',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
        ],
    ]);

    $sync->delete();

    expect(Schema::hasTable('customers'))
        ->toBeTrue()
        ->and(Sync::query()->count())
        ->toBe(0);
});

it('pauses a synchronization and rejects editing when its destination is no longer registered', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeMetadata($connection);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'customers_sync',
        'type' => DestinationType::Static,
        'destination' => 'customers',
    ]);
    $sync->forceFill(['type' => DestinationType::Static, 'destination' => 'gone'])->saveQuietly();
    $connection->metadata()->update(['revision' => $connection->draftRevision()]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and($sync->fresh()->is_paused)
        ->toBeFalse();

    expect(fn () => SyncForm::forUpdate(
        ['name' => 'Renamed', 'schema' => []],
        $sync->fresh(),
        app(DestinationFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', ['destination' => 'gone']),
        );
});

it('rejects creating a static synchronization for an unregistered destination', function () {
    expect(fn () => TestCase::makeSync([
        'type' => DestinationType::Static,
        'destination' => 'missing',
    ]))
        ->toThrow(LogicException::class);

    expect(Sync::query()->count())->toBe(0);
});

it('creates a static source and persists its cast through the synchronization form', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => [
        '_description' => new StringField(length: 100, fixed: false),
        '_code' => new StringField(length: 11, fixed: true),
    ]]);
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
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
    $mapping->getAction('addConstant')->call();
    expect($mapping->getConnectionModes()['static_1'])->not->toHaveKey('is_active');
    $mapping->getConstantValueSchema('static_1')->getComponent('static')->state('false');
    $sourceKey = $mapping->getSourceStateKeys()['static_1'];
    $form->getComponentByStatePath('schema.sources.' . $sourceKey . '.transforms')->state([
        ['type' => 'cast', 'data' => ['type' => 'boolean']],
    ]);
    expect($mapping->getConnectionModes()['static_1']['is_active'])->toBe('whole');
    $livewire->data['schema']['targets'][$mapping->getStateKeys()['is_active']] = MappingState::source('static_1');
    $state = $form->getState();
    $constant = collect($state['schema'])->firstWhere('kind', 'static');
    expect($constant)->toBe([
        'kind' => 'static',
        'target' => 'is_active',
        'static' => 'false',
        'result' => ['type' => 'boolean'],
        'transforms' => [['type' => 'cast', 'data' => ['type' => 'boolean']]],
    ]);
    $sync = Sync::create(SyncForm::forCreate($state, app(DestinationFactory::class)));
    expect(collect($sync->schema)->firstWhere('target', 'is_active')->type())->toBe(ColumnType::Boolean);
    $edit = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $edit->fill(SyncForm::fill($sync->fresh()->toArray()));
    expect($edit->getState()['schema'])->toEqual($state['schema']);
});
