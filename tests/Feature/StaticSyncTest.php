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
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\TransformsBuilder;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;
use function PHPinnacle\Ferry\Tests\Fakes\exclude_folders_transform;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';
require_once __DIR__ . '/../Fakes/ExcludeFoldersTransform.php';

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

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations, app(TransformFactory::class)));
    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->toArray()));
    expect($form->getComponent('destination')->getState())
        ->toBe($destination === null ? 'dynamic' : 'static:' . $destination);
    $livewire->data['name'] = 'Renamed';

    $dehydrated = $form->getState();
    expect($dehydrated['schema'][$destination === null ? 'bindings' : 'targets'])
        ->toEqual($data['schema'][$destination === null ? 'bindings' : 'targets']);
    $sync->update(SyncForm::forUpdate($dehydrated, $sync, $destinations, app(TransformFactory::class)));

    expect($sync->fresh()->name)
        ->toBe('Renamed')
        ->and($sync->fresh()->type)
        ->toBe($destination === null ? DestinationType::Dynamic : DestinationType::Static)
        ->and(array_column($sync->fresh()->schema, 'column', 'source'))
        ->toEqual(['_description' => 'name', '_code' => 'tax_number'])
        ->and(Schema::hasTable('ferry_sync_customers_sync'))
        ->toBe($destination === null);
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
    $transforms = app(TransformFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::mapping([
        'name' => MappingState::source('_description'),
        'is_active' => MappingState::value('true'),
        'tax_number' => MappingState::value('none'),
    ]);

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

    expect($createState['schema']['targets'] ?? $createState['schema']['bindings'])
        ->toEqual($state['targets'] ?? $state['bindings']);

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations, $transforms));

    expect(array_column($sync->schema, 'source'))
        ->toBe(['_description', null, null])
        ->and($sync->constantColumns())
        ->toBe(['is_active', 'tax_number'])
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Boolean);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getState()['schema']['targets'])->toBe($state['targets']);

    $key = $form->getComponentByStatePath('schema')->getStateKeys()['is_active'];
    $livewire->data['schema']['targets'][$key] = MappingState::value('false');
    $sync->update(SyncForm::forUpdate($form->getState(), $sync, $destinations, $transforms));

    expect($sync->fresh()->schema[1]->constant->jsonSerialize())
        ->toBe(['type' => 'boolean', 'value' => 'false'])
        ->and($sync->fresh()->schema[1]->transforms)
        ->toBe([]);
});

it('keeps a typed static value through creating and filling a dynamic synchronization', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);
    $destinations = app(DestinationFactory::class);
    $transforms = app(TransformFactory::class);
    $livewire = SchemaHost::make();
    $state = MappingState::binding(['_description' => 'title', 'priority' => ['type' => 'integer', 'value' => '5']]);

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

    expect($createState['schema']['targets'] ?? $createState['schema']['bindings'])
        ->toEqual($state['targets'] ?? $state['bindings']);

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations, $transforms));

    expect(Schema::hasColumns('ferry_sync_customers_sync', ['title', 'priority']))
        ->toBeTrue()
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Integer);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getState()['schema']['bindings'])->toEqual($state['bindings']);
});

it('keeps field and record transforms through creating, filling and updating a synchronization', function () {
    app(TransformFactory::class)->register(exclude_folders_transform());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
            '_folder' => new ScalarField(FieldType::Boolean),
        ],
    ]);
    $destinations = app(DestinationFactory::class);
    $transforms = app(TransformFactory::class);
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
        ], ['_code' => ['transforms' => [['key' => 'cast', 'params' => ['type' => 'boolean']]]]]),
        'transforms' => [['key' => 'exclude_folders']],
    ]);
    $attributes = SyncForm::forCreate($createForm->getState(), $destinations, $transforms);
    $sync = Sync::create($attributes);

    expect($sync->schema[1]->result)
        ->toBe(ColumnType::Boolean)
        ->and($sync->schema[1]->sourceType())
        ->toBe(ColumnType::String)
        ->and($sync->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])])
        ->and($sync->fresh()->transforms)
        ->toEqual([new TransformSetting('exclude_folders')]);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));
    $state = $form->getState();

    expect(array_column(array_values($state['transforms']), 'key'))
        ->toBe(['exclude_folders'])
        ->and($state)
        ->not
        ->toHaveKey('field_transforms')
        ->and($state['schema']['sources']['_code']['transforms'])
        ->toBe([['key' => 'cast', 'params' => ['type' => 'boolean']]]);

    $sync->update(SyncForm::forUpdate($state, $sync, $destinations, $transforms));

    expect($sync->fresh()->schema[1]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'boolean'])])
        ->and($sync->fresh()->transforms)
        ->toEqual([new TransformSetting('exclude_folders')]);
});

it('fills the transform rows with the fields of the selected object', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_marked' => new ScalarField(FieldType::Boolean),
        ],
    ]);
    $livewire = SchemaHost::make();

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    view()->share('errors', new ViewErrorBag);

    $render = function (array $transforms) use ($form, $connection) {
        $form->fill([
            'connection_id' => $connection->id,
            'source' => 'object-0',
            'destination' => 'dynamic',
            'transforms' => $transforms,
        ]);

        return $form->toHtml();
    };
    $bare = $render([]);
    $rows = $render([['key' => 'filter']]);

    expect(substr_count($rows, 'Deletion mark (_marked)'))
        ->toBeGreaterThan(substr_count($bare, 'Deletion mark (_marked)'))
        ->and(substr_count($rows, 'Code (_code)'))
        ->toBeGreaterThan(substr_count($bare, 'Code (_code)'));
});

it('resets the transforms with the connection and the object but not with the destination', function () {
    $livewire = SchemaHost::make();

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $settings = MappingState::mapping([], [
        '_code' => ['transforms' => [['key' => 'cast', 'params' => ['type' => 'string']]]],
    ]);
    $form->fill([
        'destination' => 'static:customers',
        'transforms' => [['key' => 'filter']],
        'schema' => $settings,
    ]);

    $form->getComponent('destination')->callAfterStateUpdated();

    expect($livewire->data['transforms'])->toHaveCount(1)->and($livewire->data['schema'])->toBe([]);

    foreach (['source', 'connection_id'] as $name) {
        $livewire->data['transforms'] = [['key' => 'filter']];
        $livewire->data['schema'] = $settings;

        $form->getComponent($name)->callAfterStateUpdated();

        expect($livewire->data['transforms'])->toBe([])->and($livewire->data['schema'])->toBe([]);
    }
});

it('rejects transforms that cannot be applied to the synchronization', function (
    array $data,
    string $error,
    array $replace,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);

    expect(fn () => SyncForm::forCreate(
        [
            'connection_id' => $connection->id,
            'name' => 'Customers',
            'code' => 'customers_sync',
            'source' => 'object-0',
            'destination' => 'dynamic',
            'schema' => MappingState::binding(['_description' => 'title']),
            ...$data,
        ],
        app(DestinationFactory::class),
        app(TransformFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.' . $error, $replace),
        );
})->with([
    'field transform with an unsupported type' => [
        ['schema' => MappingState::binding(['_description' => 'title'], [
            '_description' => ['transforms' => [['key' => 'cast', 'params' => ['type' => 'reference']]]],
        ])],
        'transform_invalid_params',
        fn () => [
            'transform' => __('phpinnacle-ferry::resources.transform.cast'),
            'error' => 'The selected type is invalid.',
        ],
    ],
    'filter on a column the object does not have' => [
        [
            'transforms' => [
                [
                    'key' => 'filter',
                    'params' => ['mode' => 'exclude', 'column' => '_gone', 'type' => 'string', 'value' => 'x'],
                ],
            ],
        ],
        'transform_column_missing',
        ['column' => '_gone'],
    ],
]);

it('allows repeated record and field transforms on submission', function (TransformScope $scope) {
    $object = new ConnectionMetadata([
        'system' => ['_folder' => new ScalarField(FieldType::Boolean)],
        'properties' => [],
    ]);
    $setting = $scope === TransformScope::Field
        ? ['key' => 'cast', 'params' => ['type' => 'string']]
        : ['key' => 'filter', 'params' => ['mode' => 'exclude', 'column' => '_folder', 'value' => 'true']];
    $schema = FormSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components([
            TransformsBuilder::make($scope, $object, app(TransformFactory::class)),
        ]);
    $schema->fill(['transforms' => [$setting, $setting]]);

    expect($schema->getState()['transforms'])->toBe([$setting, $setting]);
})->with([TransformScope::Field, TransformScope::Record]);

it('fills the selected transform block with its native field defaults', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $livewire = SchemaHost::make();

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill(['connection_id' => $connection->id, 'source' => 'object-0', 'destination' => 'dynamic']);

    $form->getComponentByStatePath('transforms')->getAction('add')->arguments(['block' => 'filter'])->call();

    /** @var array<string, array<string, mixed>> $rows */
    $rows = $livewire->data['transforms'];

    expect(array_values($rows)[0])->toBe([
        'type' => 'filter',
        'data' => ['mode' => 'exclude', 'column' => null, 'value' => null],
    ]);
});

it('keeps transform drafts on unmapped fields and persists only mapped transforms', function () {
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
    $cast = ['key' => 'cast', 'params' => ['type' => 'integer']];

    expect($form->toHtml())->toContain('data-binding-source-settings="_description"');
    $action(['transform' => 'cast'])->data(['params' => $cast['params']])->call();
    expect($mapping->getSourceSettings()['_code']['transforms'])->toBe([$cast]);
    $description = $form->getComponentByStatePath('schema.sources.' . $keys['_description'] . '.transforms')->getAction(
        'fieldTransforms',
    );
    $description(['transform' => 'cast'])->data(['params' => $cast['params']])->call();
    expect($mapping->getSourceSettings()['_description']['transforms'])->toBe([$cast]);
    $attributes = SyncForm::forCreate($form->getState(), app(DestinationFactory::class), app(TransformFactory::class));

    expect($attributes['schema'])
        ->toHaveCount(1)
        ->and($attributes['schema'][0]->transforms)
        ->toEqual([new TransformSetting('cast', ['type' => 'integer'])]);
});

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
        'schema' => MappingState::binding(['_description' => 'title', 'title' => ['type' => 'string', 'value' => 'x']]),
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
            ]),
        ],
        app(DestinationFactory::class),
        app(TransformFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.constant_invalid_value', [
                'error' => 'The selected value is invalid.',
            ]),
        );
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
        unset($page->data['schema']['bindings'][$field->getBindingKey('_description')]);
    }

    if ($change === 'renamed') {
        $field = $page->form->getComponentByStatePath('schema');
        $page->data['schema']['bindings'][$field->getBindingKey('_description')]['column'] = 'display_name';
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
        app(TransformFactory::class),
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
