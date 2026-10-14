<?php

use Filament\Actions\Action;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Pages\EditSync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;
use function PHPinnacle\Ferry\Tests\Fakes\exclude_folders_transform;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';
require_once __DIR__ . '/../Fakes/ExcludeFoldersTransform.php';

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
        'schema' => ['_description' => 'name', '_code' => 'tax_number'],
    ];

    $destinations = app(DestinationFactory::class);
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-mapping-round-trip');
    $livewire->setName('sync-mapping-round-trip');

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

    if ($destination !== null) {
        expect($form->getComponentByStatePath('schema')->getState())
            ->toBe(['name' => '_description', 'tax_number' => '_code']);
    }

    $dehydrated = $form->getState();
    expect($dehydrated['schema'])->toEqual([
        '_description' => 'name',
        '_code' => 'tax_number',
    ]);
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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-static-value-round-trip');
    $livewire->setName('sync-static-value-round-trip');
    $state = ['_description' => 'name', 'is_active' => ['value' => 'true'], 'tax_number' => ['value' => 'none']];

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

    expect($createState['schema'])->toEqual($state);

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations, $transforms));

    expect(array_column($sync->schema, 'source'))
        ->toBe(['_description', null, null])
        ->and($sync->constantColumns())
        ->toBe(['is_active', 'tax_number'])
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Boolean);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getComponentByStatePath('schema')->getState())
        ->toBe(['name' => '_description', 'is_active' => ['value' => 'true'], 'tax_number' => ['value' => 'none']]);

    $livewire->data['schema']['is_active'] = ['value' => 'false'];
    $sync->update(SyncForm::forUpdate($form->getState(), $sync, $destinations, $transforms));

    expect($sync->fresh()->schema[1]->transforms[0]->params)->toBe(['type' => 'boolean', 'value' => 'false']);
});

it('keeps a typed static value through creating and filling a dynamic synchronization', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_description' => new StringField(length: 100, fixed: false)],
    ]);
    $destinations = app(DestinationFactory::class);
    $transforms = app(TransformFactory::class);
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-dynamic-static-value');
    $livewire->setName('sync-dynamic-static-value');
    $state = ['_description' => 'title', 'priority' => ['type' => 'integer', 'value' => '5']];

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

    expect($createState['schema'])->toEqual($state);

    $sync = Sync::create(SyncForm::forCreate($createState, $destinations, $transforms));

    expect(Schema::hasColumns('ferry_sync_customers_sync', ['title', 'priority']))
        ->toBeTrue()
        ->and($sync->schema[1]->type())
        ->toBe(ColumnType::Integer);

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $form->fill(SyncForm::fill($sync->fresh()->toArray()));

    expect($form->getState()['schema'])->toEqual($state);
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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-transforms-round-trip');
    $livewire->setName('sync-transforms-round-trip');

    $createForm = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $createForm->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => ['_description' => 'name', '_code' => 'is_active', 'tax_number' => ['value' => 'none']],
        'transforms' => [['key' => 'exclude_folders']],
        'field_transforms' => ['_code' => [['key' => 'cast', 'params' => ['type' => 'boolean']]]],
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
        ->and($state['field_transforms'])
        ->toBe(['_code' => [['key' => 'cast', 'params' => ['type' => 'boolean']]]]);

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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-transform-fields');
    $livewire->setName('sync-transform-fields');

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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-transform-reset');
    $livewire->setName('sync-transform-reset');

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $fieldTransforms = ['_code' => [['key' => 'cast', 'params' => ['type' => 'string']]]];
    $form->fill([
        'destination' => 'static:customers',
        'transforms' => [['key' => 'filter']],
        'field_transforms' => $fieldTransforms,
    ]);

    $form->getComponent('destination')->callAfterStateUpdated();

    expect($livewire->data['transforms'])->toHaveCount(1)->and($livewire->data['field_transforms'])->toBe([]);

    foreach (['source', 'connection_id'] as $name) {
        $livewire->data['transforms'] = [['key' => 'filter']];
        $livewire->data['field_transforms'] = $fieldTransforms;

        $form->getComponent($name)->callAfterStateUpdated();

        expect($livewire->data['transforms'])->toBe([])->and($livewire->data['field_transforms'])->toBe([]);
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
            'schema' => ['_description' => 'title'],
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
        ['field_transforms' => ['_description' => [['key' => 'cast', 'params' => ['type' => 'reference']]]]],
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

it('allows each field transform once per field but any number of record transforms', function () {
    $repeater = fn (TransformScope $scope) => new ReflectionMethod(SyncForm::class, 'transformsRepeater')->invoke(
        null,
        $scope,
        null,
        app(TransformFactory::class),
    );

    expect($repeater(TransformScope::Field)->getMaxItems())
        ->toBe(count(app(TransformFactory::class)->forScope(TransformScope::Field)))
        ->and($repeater(TransformScope::Record)->getMaxItems())
        ->toBeNull();
});

it('preselects the only registered transform of a block when a row is added', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-single-transform');
    $livewire->setName('sync-single-transform');

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill(['connection_id' => $connection->id, 'source' => 'object-0', 'destination' => 'dynamic']);

    $form->getComponentByStatePath('transforms')->getAction('add')->call();

    /** @var array<string, array<string, mixed>> $rows */
    $rows = $livewire->data['transforms'];

    expect(array_values($rows)[0])->toBe([
        'key' => 'filter',
        'params' => ['mode' => 'exclude', 'column' => null, 'value' => null],
    ]);
});

it('edits the transforms of a mapped field from its row and drops those of unmapped fields', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
    ]);
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-field-transforms-action');
    $livewire->setName('sync-field-transforms-action');

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Codes',
        'code' => 'codes_sync',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => ['_code' => 'code'],
    ]);
    view()->share('errors', new ViewErrorBag);
    $action = $form->getComponentByStatePath('schema')->getAction('fieldTransforms');
    $cast = ['key' => 'cast', 'params' => ['type' => 'integer']];

    expect(substr_count($form->toHtml(), 'Transforms (0)'))->toBe(1);

    $action(['source' => '_code'])->data(['transforms' => ['row' => $cast]])->call();

    expect($livewire->data['field_transforms'])
        ->toBe(['_code' => [$cast]])
        ->and($action(['source' => '_code'])->getLabel())
        ->toBe('Transforms (1)')
        ->and(fn () => $action(['source' => '_gone'])->data(['transforms' => []])->call())
        ->toThrow(ValidationException::class);

    $livewire->data['field_transforms']['_description'] = [$cast];
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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-dynamic-duplicate-value');
    $livewire->setName('sync-dynamic-duplicate-value');

    $form = SyncForm::configure(FormSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'name' => 'Customers',
        'code' => 'customers_sync',
        'source' => 'object-0',
        'destination' => 'dynamic',
        'schema' => ['_description' => 'title', 'title' => ['type' => 'string', 'value' => 'x']],
    ]);

    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('rejects a static value that does not fit the destination field', function () {
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
            'schema' => [
                '_description' => 'name',
                'tax_number' => ['value' => 'x'],
                'is_active' => ['value' => 'maybe'],
            ],
        ],
        app(DestinationFactory::class),
        app(TransformFactory::class),
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.transform_invalid_params', [
                'transform' => __('phpinnacle-ferry::resources.transform.static_value'),
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
        unset($page->data['schema'][$field->getBindingKey('_description')]);
    }

    if ($change === 'renamed') {
        $field = $page->form->getComponentByStatePath('schema');
        $page->data['schema'][$field->getBindingKey('_description')]['column'] = 'display_name';
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
