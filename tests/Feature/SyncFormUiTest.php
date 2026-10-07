<?php

use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use PHPinnacle\Ferry\Data\FieldMapping as SchemaMapping;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

/** @return Collection<string, Select> */
$selects = function (array $state) {
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-test');
    $livewire->setName('sync-form-test');
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

it('binds dynamic fields and connects static fields to the declared destination fields', function () {
    app(DestinationFactory::class)->register(customers_destination());

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
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
                field: new StringField(length: 10, fixed: false),
            ),
        ],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    $form = function (string $destination) use ($connection) {
        $livewire = new class extends LivewireComponent implements HasSchemas {
            use InteractsWithSchemas;

            /** @var array<string, mixed> */
            public array $data = [];
        };
        $livewire->setId('sync-form-sources-test');
        $livewire->setName('sync-form-sources-test');
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

    expect(array_column($binding->getSources(), 'label', 'id'))
        ->toBe([
            '_idrref' => __('phpinnacle-ferry::resources.sync.system_fields._idrref') . ' (_idrref)',
            '_description' => __('phpinnacle-ferry::resources.sync.system_fields._description') . ' (_description)',
            'property-1' => 'Title 1 (_fld1)',
        ])
        ->and(array_column($mapping->getSources(), 'type', 'id'))
        ->toBe(['_description' => 'string', 'property-1' => 'string'])
        ->and(array_column($mapping->getTargets(), 'required', 'id'))
        ->toBe(['name' => true, 'tax_number' => true, 'is_active' => false])
        ->and(array_column($mapping->getTargets(), 'type', 'id'))
        ->toBe(['name' => 'string', 'tax_number' => 'string', 'is_active' => 'boolean'])
        ->and($form('')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldBinding::class)
        ->and($form('customers')->getComponentByStatePath('schema'))
        ->toBeInstanceOf(FieldMapping::class);
});

it('validates the static mapping against the declared destination fields', function (array $mapping, bool $valid) {
    app(DestinationFactory::class)->register(customers_destination());

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-static-mapping-test');
    $livewire->setName('sync-form-static-mapping-test');
    $livewire->data = [
        'name' => 'Customers',
        'code' => 'customers',
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => 'static:customers',
        'schema' => $mapping,
    ];

    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->operation('create'),
    );

    if ($valid) {
        expect($schema->getState()['schema'])
            ->toBe(array_flip($mapping))
            ->and(SyncForm::forCreate($schema->getState(), app(DestinationFactory::class))['type'])
            ->toBe(DestinationType::Static);

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid mapping' => [['name' => '_description', 'tax_number' => '_code'], true],
    'missing required field' => [['name' => '_description'], false],
    'incompatible types' => [['name' => '_description', 'tax_number' => '_code', 'is_active' => '_code'], false],
    'empty mapping' => [[], false],
]);

it('validates the bound column names of a dynamic synchronization', function (string $column, bool $valid) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);
    $sync = TestCase::makeSync(['connection_id' => $connection->id]);

    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-mapping-test');
    $livewire->setName('sync-form-mapping-test');

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
        'schema' => ['_idrref' => $column],
    ]);

    if ($valid) {
        expect($schema->getState()['schema'])->toBe(['_idrref' => $column]);

        return;
    }

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'valid column' => ['external_id', true],
    'uppercase column' => ['External', false],
    'reserved column' => ['idrref', false],
]);

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
    app(DestinationFactory::class)->register(new \PHPinnacle\Ferry\Destinations\StaticDestination(
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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-destination-test');
    $livewire->setName('sync-form-destination-test');
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
    app(DestinationFactory::class)->register(new \PHPinnacle\Ferry\Destinations\StaticDestination(
        key: 'other_customers',
        label: 'Other customers',
        table: 'other_customers',
        primaryKey: 'id',
        fields: customers_destination()->fields,
    ));
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-switch-destination-test');
    $livewire->setName('sync-form-switch-destination-test');
    $form = SyncForm::configure(FilamentSchema::make($livewire)->statePath('data')->operation('create'));
    $form->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $from,
        'schema' => ['_description' => 'name'],
    ]);

    $form->getComponent('destination')->state($to)->callAfterStateUpdated();

    expect($livewire->data['destination'])->toBe($to);
    expect($livewire->data['schema'])->toBe([]);
    expect($form->getComponentByStatePath('schema'))
        ->toBeInstanceOf($to === 'dynamic' ? FieldBinding::class : FieldMapping::class);
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
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-warnings-test');
    $livewire->setName('sync-form-warnings-test');
    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)->statePath('data')->record($sync)->operation('edit'),
    );
    $schema->fill([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'destination' => $type === DestinationType::Static ? 'static:' . $sync->destination : 'dynamic',
        'schema' => ['_description' => 'name', '_missing' => 'gone'],
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
