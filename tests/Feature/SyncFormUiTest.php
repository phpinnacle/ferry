<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Forms\FieldBinding;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\SyncForm;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

/** @return Collection<string, TextInput> */
$fields = function (string $operation, ?Sync $record = null) {
    $livewire = new class extends LivewireComponent implements HasSchemas {
        use InteractsWithSchemas;

        /** @var array<string, mixed> */
        public array $data = [];
    };
    $livewire->setId('sync-form-test');
    $livewire->setName('sync-form-test');

    return collect(
        SyncForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->record($record)
                ->operation($operation),
        )->getFlatComponents(),
    )
        ->filter(fn ($component) => $component instanceof TextInput)
        ->keyBy(fn (TextInput $field) => $field->getName());
};

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

it('offers dynamic and registered static destinations in a single select', function () use ($selects) {
    app(StaticDestinationRegistry::class)->register(new FakeCustomersDestination);

    $destinationSelect = $selects([])->get('static_destination');

    expect($destinationSelect)
        ->not
        ->toBeNull()
        ->and($destinationSelect?->getPlaceholder())
        ->toBe(__('phpinnacle-ferry::resources.sync.destination_types.dynamic'))
        ->and($destinationSelect?->canSelectPlaceholder())
        ->toBeTrue()
        ->and($destinationSelect?->getOptions())
        ->toBe([
            'customers' => 'Customers',
        ]);
});

it('locks the destination code once the synchronization exists', function () use ($fields) {
    $sync = TestCase::makeSync();

    expect($fields('create')->get('code')?->isDisabled())
        ->toBeFalse()
        ->and($fields('edit', $sync)->get('code')?->isDisabled())
        ->toBeTrue();
});

it('binds dynamic fields and connects static fields to the declared destination fields', function () {
    app(StaticDestinationRegistry::class)->register(new FakeCustomersDestination);

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
            'static_destination' => $destination,
        ];

        return SyncForm::configure(
            FilamentSchema::make($livewire)
                ->statePath('data')
                ->operation('create'),
        );
    };

    /** @var FieldBinding $binding */
    $binding = $form('')->getComponent('schema');
    /** @var FieldMapping $mapping */
    $mapping = $form('customers')->getComponent('static_mapping');

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
        ->and($form('')->getComponent('static_mapping', withHidden: true)?->isHidden())
        ->toBeTrue()
        ->and($form('customers')->getComponent('schema', withHidden: true)?->isHidden())
        ->toBeTrue();
});

it('validates the static mapping against the declared destination fields', function (array $mapping, bool $valid) {
    app(StaticDestinationRegistry::class)->register(new FakeCustomersDestination);

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
        'static_destination' => 'customers',
        'static_mapping' => $mapping,
    ];

    $schema = SyncForm::configure(
        FilamentSchema::make($livewire)
            ->statePath('data')
            ->operation('create'),
    );

    if ($valid) {
        expect($schema->getState()['static_mapping'])->toBe($mapping);

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
