<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fixtures/MappingState.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/SyncForm.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('keeps reference destinations distinct from ordinary UUID transform outputs', function () {
    app(TransformRegistry::class)->register(Transformation::make('as_uuid')
        ->inputSchema(['type' => 'string'])
        ->outputSchema(['type' => 'string', 'format' => 'uuid']));
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: 'id',
        fields: [new DestinationField('owner', 'Owner', ColumnType::Reference, required: true)],
    ));
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => [
        '_owner' => new ScalarField(FieldType::Uuid),
        '_description' => new StringField(100, false),
    ]]);
    $form = SyncForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'static:owners',
        'schema' => MappingState::mapping(['owner' => MappingState::source('_owner')], [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->getSourceSchema()['properties']['_owner'])->toMatchArray([
        'type' => 'string',
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    expect($mapping->getConnectionModes())
        ->toHaveKey('_owner.owner', 'whole')
        ->not->toHaveKey('_description.owner');
    $state = $form->getState();
    $owner = collect($state['schema'])->firstWhere('source', '_owner');
    expect($owner['result'])->toMatchArray(['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true]);
    expect(SchemaMapping::fromArray($owner)->type())->toBe(ColumnType::Reference);

    $mapping->rawState(MappingState::encode(
        $mapping,
        ['owner' => MappingState::source('_description')],
        [
            '_description' => ['transforms' => [['type' => 'as_uuid', 'data' => []]]],
        ],
    ));
    expect($form->getState(...))->toThrow(ValidationException::class);
});

it('updates only static cards and the changed transform editor in a large dynamic form', function () {
    app(TransformRegistry::class)->register(Trim::make());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = [];
    for ($index = 0; $index < 165; $index++) {
        $properties[] = new MetadataProperty(
            id: 'property-' . $index,
            name: '_fld' . $index,
            code: $index,
            kind: PropertyKind::Field,
            label: 'Field ' . $index,
            title: 'Field ' . $index,
            field: new StringField(100, false),
        );
    }
    TestCase::makeMetadata($connection, ['properties' => $properties]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\SyncForm::class, ['state' => [
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
    ]]);
    $sourceKey = $page->instance()->form->getComponentByStatePath('schema')->getBindingKey('_fld164');
    $page->set('data.schema.bound.' . $sourceKey, true)
        ->set('data.schema.bindings.' . $sourceKey . '.target', 'product_code');

    $page->callAction(TestAction::make('addConstant')->schemaComponent('schema', 'form'));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))
        ->toContain('data-binding-constant=')
        ->not->toContain('data-binding-source=');
    $field = $page->instance()->form->getComponentByStatePath('schema');
    $constant = array_key_first($field->getConstants());
    $page->set('data.schema.bindings.' . $constant . '.input.static', 'value');
    $draft = $page->get('data.schema');

    $path = 'schema.sources.' . $field->getSourceStateKeys()['_fld0'] . '.transforms';
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'trim']));
    $page->assertSet('data.' . $path, [['type' => 'trim', 'data' => []]]);
    expect($page->effects)->not->toHaveKey('html');
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);

    $page->callAction($action->arguments(['block' => 'trim', 'index' => 0, 'remove' => true]));
    $page->assertSet('data.' . $path, []);
    expect(array_keys($page->effects['partials']))->toBe(['schema-component::form.' . $path]);
    expect($page->get('data.schema'))->toBe($draft);

    $page->callAction(TestAction::make('removeConstant')
        ->schemaComponent('schema', 'form')
        ->arguments([
            'constant' => $constant,
        ]));
    expect($page->effects)->not->toHaveKey('html');
    expect(implode('', $page->effects['partials']))->not->toContain('data-binding-constant=', 'data-binding-source=');
    $page->assertSet('data.schema.bindings.' . $sourceKey . '.target', 'product_code');
});

it('refreshes mapping compatibility when transform actions change the source type', function () {
    app(TransformRegistry::class)->register(Trim::make());
    app(DestinationFactory::class)->register(customers_destination());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => [
        '_description' => new StringField(100, false),
        '_code' => new StringField(9, true),
    ]]);
    $page = Livewire::test(\PHPinnacle\Ferry\Tests\Fixtures\SyncForm::class, ['state' => [
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
    ]]);
    $field = $page->instance()->form->getComponentByStatePath('schema');
    expect($field->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    $path = 'schema.sources.' . $field->getSourceStateKeys()['_description'] . '.transforms';
    $targets = [$field->getStateKeys()['name'] => MappingState::source('_description')];
    $page->set('data.schema.targets', $targets);
    $action = TestAction::make('fieldTransforms')->schemaComponent($path, 'form');
    $page->callAction($action->arguments(['block' => 'cast']), ['data' => ['type' => 'boolean']]);
    expect($page->instance()->form->getComponentByStatePath('schema')->getConnectionModes()['_description'])
        ->toBe(['is_active' => 'whole']);
    $card = 'schema-component::form.schema.card_' . $field->getSourceStateKeys()['_description'];
    expect($page->effects)->not->toHaveKey('html');
    expect($page->effects['partials'])->not->toHaveKey('schema-component::form.schema');
    expect($page->effects['partials'][$card])
        ->toContain('data-mapping-source-settings="_description"', '>boolean</span>')
        ->not->toContain('data-mapping-source-settings="_code"');
    expect($page->effects['partials']['schema-component::form.schema.configuration'])
        ->toContain('updateConfiguration(');
    $page->callAction($action->arguments(['block' => 'cast', 'index' => 0, 'remove' => true]));
    expect($page->instance()->form->getComponentByStatePath('schema')->getConnectionModes()['_description'])
        ->toHaveKeys(['name', 'tax_number'])
        ->not->toHaveKey('is_active');
    expect($page->effects['partials'])
        ->toHaveKeys([$card, 'schema-component::form.schema.configuration'])
        ->not->toHaveKey('schema-component::form.schema');
    $page->assertSet('data.schema.targets', $targets);

    $page->callAction(TestAction::make('addConstant')->schemaComponent('schema', 'form'));
    expect(array_keys($page->effects['partials']))->toEqualCanonicalizing([
        'schema-component::form.schema.staticSources',
        'schema-component::form.schema.configuration',
    ]);
    expect($page->effects['partials']['schema-component::form.schema.staticSources'])
        ->toContain('data-mapping-static-source="static_1"')
        ->not->toContain('data-mapping-source-settings="_description"', 'data-mapping-source-settings="_code"');
    $field = $page->instance()->form->getComponentByStatePath('schema');
    $staticPath = 'schema.sources.' . $field->getSourceStateKeys()['static_1'] . '.transforms';
    $page->update(calls: [[
        'method' => 'mountAction',
        'params' => ['fieldTransforms', ['block' => 'trim'], ['schemaComponent' => 'form.' . $staticPath]],
        'path' => '',
    ]], updates: ['data.schema.constants.static_1.static' => '  draft  ']);
    $page
        ->assertHasNoErrors()
        ->assertSet('data.schema.constants.static_1.static', '  draft  ')
        ->assertSet('data.' . $staticPath, [['type' => 'trim', 'data' => []]]);
    expect($page->effects)->not->toHaveKey('html');
    expect($page->effects['partials'])->not->toHaveKey('schema-component::form.schema');
    $page->callAction(TestAction::make('removeConstant')
        ->schemaComponent('schema', 'form')
        ->arguments(['constant' => 'static_1']));
    expect(array_keys($page->effects['partials']))->toEqualCanonicalizing([
        'schema-component::form.schema.staticSources',
        'schema-component::form.schema.configuration',
    ]);
    $page->assertSet('data.schema.constants', [])->assertSet('data.schema.targets', $targets);
});

it('shows Rosetta string bounds and exact numeric metadata in both mapping modes', function (string $destination) {
    app(DestinationFactory::class)->register(customers_destination());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(8, true),
            '_description' => new StringField(100, false),
        ],
        'properties' => [new MetadataProperty(
            id: 'price-id',
            name: '_fld17',
            code: 17,
            kind: PropertyKind::Field,
            label: 'Price',
            title: 'Price',
            field: new NumberField(20, 2, true),
        )],
    ]);
    $form = SyncForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $destination,
        'schema' => [],
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    $sources = array_column($mapping->getSources(), null, 'id');
    expect($sources['_code']['presentation']['constraints'])
        ->toBe([['kind' => 'length', 'value' => '8', 'scope' => []]])
        ->and($sources['_description']['presentation']['constraints'])
        ->toBe([['kind' => 'length', 'value' => '≤ 100', 'scope' => []]])
        ->and($sources['_fld17']['description'])
        ->toBe('Digits: 20; Decimal places: 2; Value: 0 … 999999999999999999.99')
        ->and($sources['_fld17']['presentation']['constraints'])
        ->toBe([['kind' => 'range', 'value' => '≥ 0', 'scope' => []]]);
    $key = $mapping->getSourceStateKeys()['_code'];
    expect(
        $form
            ->getComponentByStatePath('schema.sources.' . $key . '.transforms')
            ->getInputSchema()
            ->toArray(),
    )
        ->toMatchArray(['type' => 'string', 'minLength' => 8, 'maxLength' => 8]);
})->with([DestinationType::Dynamic->value, 'static:customers']);

/** @return Collection<string, Select> */
$selects = function (array $state) {
    $livewire = SchemaHost::make();
    $livewire->data = $state;

    return collect(
        SyncForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->operation('create'),
        )->getFlatComponents(),
    )
        ->filter(fn ($component) => $component instanceof Select)
        ->keyBy(fn (Select $field) => $field->getName());
};

it('defaults to the first available connection and object with a dynamic destination', function () {
    TestCase::makeConnection(['code' => 'inactive', 'name' => 'A inactive', 'is_active' => false]);
    TestCase::makeConnection(['code' => 'preparing', 'name' => 'A preparing']);
    TestCase::makeConnection(['code' => 'later', 'name' => 'Z connection'], ['status' => StructureStatus::Ready]);
    $connection = TestCase::makeConnection(['name' => 'B connection'], [
        'status' => StructureStatus::Ready,
        'generation' => 1,
    ]);
    TestCase::makeMetadata($connection, ['title' => 'Z object']);
    $first = TestCase::makeMetadata($connection, [
        'external_id' => 'first',
        'name' => '_reference1',
        'title' => 'A object',
    ]);
    app(DestinationFactory::class)->register(customers_destination());

    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'connection_id' => $connection->id,
        'source' => $first->external_id,
        'destination' => DestinationType::Dynamic->value,
    ]);
    foreach (['connection_id', 'source', 'destination'] as $name) {
        expect($form->getComponent($name)->canSelectPlaceholder())->toBeFalse();
    }
});

it('leaves unavailable connections and objects unselected', function () {
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill();

    expect($livewire->data)->toMatchArray([
        'connection_id' => null,
        'source' => null,
        'destination' => DestinationType::Dynamic->value,
    ]);
});

it('selects the first object and clears mappings when changing connections', function (bool $hasObjects) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $next = TestCase::makeConnection(['code' => 'next'], ['status' => StructureStatus::Ready, 'generation' => 1]);
    if ($hasObjects) {
        TestCase::makeMetadata($next, ['external_id' => 'next-object']);
    }
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
        'schema' => MappingState::binding(['_idrref' => 'external_id']),
    ]);

    $form->getComponent('connection_id')->state($next->id)->callAfterStateUpdated();

    expect($livewire->data['source'])->toBe($hasObjects ? 'next-object' : null);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping->isSourceUsed('_idrref'))->toBeFalse();
})->with([true, false]);

it('binds dynamic fields and connects static fields to the declared destination fields', function () {
    app(DestinationFactory::class)->register(customers_destination());

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [
            new MetadataProperty(
                id: 'property-1',
                name: '_fld1',
                code: 1,
                kind: PropertyKind::Field,
                label: 'Label 1',
                title: 'Title 1',
                field: new ScalarField(FieldType::Uuid),
            ),
        ],
    ]);

    $form = function (string $destination) use ($connection) {
        $livewire = SchemaHost::make();
        $livewire->data = [
            'connection_id' => $connection->id,
            'source' => 'object-0',
            'destination' => $destination !== '' ? 'static:' . $destination : DestinationType::Dynamic->value,
        ];

        return SyncForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->operation('create'),
        );
    };

    /** @var FieldBinding $binding */
    $binding = $form('')->getComponentByStatePath('schema');
    /** @var FieldMapping $mapping */
    $mapping = $form('customers')->getComponentByStatePath('schema');

    expect($mapping->hasStaticValues())->toBeTrue();
    expect($mapping->hasStaticForms())->toBeFalse();

    expect(array_column($binding->getSources(), 'label', 'id'))
        ->toBe([
            '_idrref' => __('phpinnacle-ferry::resources.sync.system_fields._idrref'),
            '_description' => __('phpinnacle-ferry::resources.sync.system_fields._description'),
            '_fld1' => 'Title 1',
        ])
        ->and(array_column($binding->getSources(), 'name', 'id'))
        ->toBe(['_idrref' => '_idrref', '_description' => '_description', '_fld1' => '_fld1'])
        ->and(array_column($mapping->getSources(), 'type', 'id'))
        ->toBe(['_description' => 'string', '_fld1' => 'string'])
        ->and(array_column($mapping->getTargets(), 'required', 'id'))
        ->toBe(['name' => true, 'tax_number' => true, 'is_active' => false])
        ->and(array_column($mapping->getTargets(), 'type', 'id'))
        ->toBe(['name' => 'string', 'tax_number' => 'string', 'is_active' => 'boolean'])
        ->and($form('')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldBinding::class)
        ->and($form('customers')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldMapping::class);

    expect($mapping->getConnectionModes()['_fld1'] ?? [])->toBe([]);

    $schema = $form('customers');
    $schema->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => [],
    ]);
    $mapping = $schema->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_fld1'];
    $schema
        ->getComponentByStatePath('schema.sources.' . $key . '.transforms')
        ->state([['type' => 'cast', 'data' => ['type' => 'string']]]);
    expect($mapping->getConnectionModes()['_fld1'])
        ->toBe(['name' => 'whole', 'tax_number' => 'whole']);
});

it('validates the static mapping against the declared destination fields', function (array $mapping, bool $valid) {
    app(DestinationFactory::class)->register(customers_destination());

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
    ]);

    $livewire = SchemaHost::make();
    $livewire->data = [
        'name' => 'Customers',
        'code' => 'customers',
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => MappingState::mapping($mapping),
    ];

    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->operation('create'),
    );

    $schema->fill($livewire->data);

    if ($valid) {
        expect(array_map(fn (array $row) => array_diff_key(
            $row,
            array_flip(['field', 'result']),
        ), $schema->getState()['schema']))
            ->toBe(MappingState::mapping($mapping, [
                '_description' => ['transforms' => []],
                '_code' => ['transforms' => []],
            ]))
            ->and(
                SyncForm::forCreate(
                    $schema->getState(),
                    app(DestinationFactory::class),
                )['type'],
            )
            ->toBe(DestinationType::Static);

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid mapping' => [
        ['name' => MappingState::source('_description'), 'tax_number' => MappingState::source('_code')],
        true,
    ],
    'missing required field' => [['name' => MappingState::source('_description')], false],
    'incompatible types' => [
        [
            'name' => MappingState::source('_description'),
            'tax_number' => MappingState::source('_code'),
            'is_active' => MappingState::source('_code'),
        ],
        false,
    ],
    'empty mapping' => [[], false],
]);

it('validates the bound column names of a dynamic synchronization', function (string $column, bool $valid) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $sync = TestCase::makeSync(['connection_id' => $connection->id]);

    $livewire = SchemaHost::make();

    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->record($sync)
            ->operation('edit'),
    );
    $schema->fill([
        'name' => $sync->name,
        'code' => $sync->code,
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
        'schema' => MappingState::binding(['_idrref' => $column]),
    ]);

    if ($valid) {
        expect(array_map(fn (array $row) => array_diff_key(
            $row,
            array_flip(['field', 'result']),
        ), $schema->getState()['schema']))
            ->toBe(MappingState::binding(['_idrref' => $column], ['_idrref' => ['transforms' => []]]));

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid column' => ['external_id', true],
    'uppercase column' => ['External', false],
    'reserved column' => ['idrref', false],
]);

it('creates every parameter of a chosen transform so that live inputs can bind to them', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => ['_folder' => new ScalarField(FieldType::Boolean)]]);
    $livewire = SchemaHost::make();
    $schema = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $schema->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => DestinationType::Dynamic->value,
        'schema' => [],
    ]);

    $mapping = $schema->getComponentByStatePath('schema');
    $key = $mapping->getSourceStateKeys()['_folder'];
    $field = $schema->getComponentByStatePath('schema.sources.' . $key . '.transforms');
    $action = $field->getAction('fieldTransforms')(['block' => 'filter']);
    $components = $action->getSchema(FilamentSchema::make($livewire))->getFlatComponents();
    $value = collect($components)->first(
        fn ($component) => $component instanceof Textarea && $component->getName() === 'condition',
    );

    expect($value)
        ->toBeInstanceOf(Textarea::class)
        ->and($value->getPlaceholder())
        ->toBe('@ == true')
        ->and(collect($components)->contains(
            fn ($component) => method_exists($component, 'getName') && $component->getName() === 'column',
        ))
        ->toBeFalse();
});

it('orders source objects with references first, obsolete ones last and titles case-insensitively', function () use (
    $selects,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $objects = [
        [MetadataKind::Reference, 'Контрагенты'],
        [MetadataKind::Reference, 'авансы'],
        [MetadataKind::Document,  'Заказ'],
        [MetadataKind::Reference, '(Не используется) Старое'],
        [MetadataKind::Reference, 'Номенклатура 10'],
        [MetadataKind::Reference, 'Номенклатура 2'],
    ];

    foreach ($objects as $index => [$kind, $title]) {
        ConnectionMetadata::create([
            'connection_id' => $connection->id,
            'external_id' => 'object-' . $index,
            'name' => '_reference' . $index,
            'code' => $index,
            'kind' => $kind,
            'label' => 'Label' . $index,
            'title' => $title,
            'system' => [],
            'properties' => [],
            'values' => [],
            'position' => $index,
            'revision' => $connection->generation,
        ]);
    }

    $options = $selects(['connection_id' => $connection->id])->get('source')?->getOptions();

    expect(array_values($options ?? []))->toBe([
        'авансы',
        'Контрагенты',
        'Номенклатура 2',
        'Номенклатура 10',
        'Заказ',
        '(Не используется) Старое',
    ]);
});

it('offers dynamic and every registered static destination in one select', function () use ($selects) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'dynamic',
        label: 'Static destination named dynamic',
        table: 'other_customers',
        primaryKey: 'id',
        fields: customers_destination()->fields,
    ));

    $fields = $selects([]);

    expect($fields->keys()->all())->toBe(['connection_id', 'source', 'destination']);
    expect($fields->get('destination')->getOptions())->toBe([
        'dynamic' => DestinationType::Dynamic->getLabel(),
        'static:customers' => 'Customers',
        'static:dynamic' => 'Static destination named dynamic',
    ]);
});

it('validates the combined destination selection', function (?string $destination) {
    app(DestinationFactory::class)->register(customers_destination());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Customers',
        'code' => 'customers',
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [],
    ]);
    $form->getComponent('destination')->state($destination)->callAfterStateUpdated();

    try {
        $form->getState();
        test()->fail('A destination from the available options must be selected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.destination');
    }
})->with([null, 'static:missing', 'unknown']);

it('resets the shared schema when switching destinations', function (string $from, string $to) {
    app(DestinationFactory::class)->register(customers_destination());
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'other_customers',
        label: 'Other customers',
        table: 'other_customers',
        primaryKey: 'id',
        fields: customers_destination()->fields,
    ));
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => ['_description' => new StringField(length: 100, fixed: false)]]);
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $from,
        'schema' => $from === 'dynamic'
            ? MappingState::binding(['_description' => 'name'])
            : MappingState::mapping(['name' => MappingState::source('_description')]),
    ]);

    $form->getComponent('destination')->state($to)->callAfterStateUpdated();

    expect($livewire->data['destination'])->toBe($to);
    $mapping = $form->getComponentByStatePath('schema');
    expect($mapping)
        ->toBeInstanceOf($to === 'dynamic' ? FieldBinding::class : FieldMapping::class);
    expect($mapping->isSourceUsed('_description'))->toBeFalse();
    expect(array_map(
        fn (array $settings) => $settings['transforms'],
        $mapping->getSourceSettings(),
    ))->toBe(['_description' => []]);
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    expect($form->toHtml())->toContain('Field Mapping');
})->with([
    'dynamic to static' => ['dynamic', 'static:customers'],
    'static to dynamic' => ['static:customers', 'dynamic'],
    'static to another static' => ['static:customers', 'static:other_customers'],
]);

it('passes outdated mappings to the field warnings instead of a separate entry', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => new ScalarField(FieldType::Boolean),
        ],
    ]);
    $sync = new Sync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'customers' : null,
        'schema' => [
            new SchemaMapping('_description', 'name', new StringField(length: 100, fixed: false)),
            new SchemaMapping('_missing', 'gone', new StringField(length: 100, fixed: false)),
        ],
    ]);
    $livewire = SchemaHost::make();
    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'),
    );
    $schema->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $type === DestinationType::Static ? 'static:' . $sync->destination : 'dynamic',
        'schema' => $type === DestinationType::Dynamic
            ? MappingState::binding(['_description' => 'name', '_missing' => 'gone'])
            : MappingState::mapping([
                'name' => MappingState::source('_description'),
                'gone' => MappingState::source('_missing'),
            ]),
    ]);

    $field = $schema->getComponentByStatePath('schema');

    expect($schema->getComponent('broken_mappings', withHidden: true))->toBeNull();

    if ($field instanceof FieldBinding) {
        expect(array_keys($field->getWarnings()))->toBe(['_description', '_missing']);
    } else {
        expect($field)->toBeInstanceOf(FieldMapping::class);
        expect(array_keys($field->getSourceWarnings()))->toBe(['_description', '_missing']);
        expect(array_keys($field->getTargetWarnings()))->toBe(['name', 'gone', 'tax_number']);
    }
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('warns on a changed source used only by a filter', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['system' => [
        '_marked' => new StringField(length: 10, fixed: false),
    ]]);
    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new SchemaMapping('_marked', null, new ScalarField(FieldType::Boolean), transforms: [
                new \PHPinnacle\Ferry\Data\TransformSetting('filter', [
                    'mode' => 'exclude',
                    'condition' => '@ == true',
                    'missing_or_null_behavior' => 'include',
                ]),
            ]),
        ],
    ]);
    $form = SyncForm::configure(
        FilamentSchema::make(SchemaHost::make())->statePath('data')->record($sync)->operation('edit'),
    );
    $form->fill(SyncForm::fill($sync->toArray()));

    expect($form->getComponentByStatePath('schema')->getWarnings())->toHaveKey('_marked');
});

it('edits physical source names while keeping metadata identities through column renames', function (DestinationType $type) {
    app(DestinationFactory::class)->register(customers_destination());
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $property = new MetadataProperty(
        id: '63e1c829-8a21-4ac1-b2a0-353d38184fb0',
        name: '_fld17',
        code: 17,
        kind: PropertyKind::Field,
        label: 'Email',
        title: 'Email',
        field: new StringField(length: 100, fixed: false),
    );
    $object = TestCase::makeMetadata($connection, [
        'system' => ['_code' => new StringField(length: 11, fixed: true)],
        'properties' => [$property],
    ]);
    $transforms = [[
        'type' => 'filter',
        'data' => [
            'mode' => 'exclude',
            'condition' => "@ == 'blocked'",
            'missing_or_null_behavior' => 'include',
        ],
    ]];
    $livewire = SchemaHost::make();
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => $object->external_id,
        'destination' => $type === DestinationType::Dynamic ? 'dynamic' : 'static:customers',
        'name' => 'Physical names',
        'code' => 'physical_names',
        'schema' => [
            ['kind' => 'source', 'source' => '_fld17', 'target' => 'name', 'transforms' => $transforms],
            ['kind' => 'source', 'source' => '_code', 'target' => 'tax_number', 'transforms' => []],
        ],
    ]);
    $state = $form->getState();
    $attributes = SyncForm::forCreate($state, app(DestinationFactory::class));
    expect(array_column($state['schema'], 'source'))->toContain('_fld17')->not->toContain($property->id);
    expect(array_column($attributes['schema'], 'source'))->toContain($property->id)->not->toContain('_fld17');
    $sync = Sync::create($attributes);
    $stored = $sync->fresh()->toArray()['schema'];

    $object->properties = [new MetadataProperty(
        id: $property->id,
        name: '_fld18',
        code: 18,
        kind: $property->kind,
        label: $property->label,
        title: $property->title,
        field: $property->field,
    )];
    $object->save();
    $edit = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'));
    $edit->fill(SyncForm::fill($sync->fresh()->toArray()));
    $edited = $edit->getState();
    expect(array_column($edited['schema'], 'source'))->toContain('_fld18')->not->toContain('_fld17', $property->id);
    expect(SyncForm::forUpdate($edited, $sync, app(DestinationFactory::class))['schema'])
        ->toEqual($stored)
        ->and($sync->columnMap($object)['_fld18'])
        ->toBe('name');
})->with([DestinationType::Dynamic, DestinationType::Static]);

it('shows each composite column as a separate source card and saves only its selected binding', function (string $destination) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'owners',
        label: 'Owners',
        table: 'owners',
        primaryKey: 'id',
        fields: [
            new DestinationField('owner', 'Owner', ColumnType::Reference, required: true),
        ],
    ));
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, ['properties' => [new MetadataProperty(
        'union-id',
        '_fld17',
        17,
        PropertyKind::Field,
        'Owner',
        'Owner',
        new UnionField([
            new ReferenceField('catalog-a'),
            new ReferenceField('catalog-b'),
        ]),
    )]]);
    $form = SyncForm::configure(FilamentSchema::make(SchemaHost::make())->statePath('data')->operation('create'));
    $form->fill([
        'name' => 'Owners',
        'code' => 'owners',
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $destination,
        'schema' => $destination === DestinationType::Dynamic->value
            ? MappingState::binding(['_fld17_rrref' => 'owner'])
            : MappingState::mapping(['owner' => MappingState::source('_fld17_rrref')]),
    ]);
    $mapping = $form->getComponentByStatePath('schema');
    $sources = array_column($mapping->getSources(), null, 'id');
    expect(array_keys($sources))
        ->toContain('_fld17_type', '_fld17_rtref', '_fld17_rrref')
        ->not
        ->toContain('_fld17')
        ->and($sources['_fld17_type']['label'])
        ->toBe('Owner · ' . __('phpinnacle-ferry::resources.sync.components.type'));
    $data = SyncForm::forCreate($form->getState(), app(DestinationFactory::class));
    $bound = array_values(array_filter($data['schema'], fn (array $row) => $row['target'] !== null));
    expect($bound)
        ->toHaveCount(1)
        ->and($bound[0]['source'])
        ->toBe('union-id:rrref')
        ->and($bound[0]['target'])
        ->toBe('owner')
        ->and($bound[0]['field'])
        ->toBe(['type' => 'uuid']);
    $filled = SyncForm::fill([...$data, 'type' => $data['type']->value, 'destination' => 'owners']);
    $bound = array_values(array_filter($filled['schema'], fn (array $row) => $row['target'] !== null));
    expect($bound[0]['source'])->toBe('_fld17_rrref');
})->with([DestinationType::Dynamic->value, 'static:owners']);
