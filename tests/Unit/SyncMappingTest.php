<?php

use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    $this->object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => new StringField(length: 100, fixed: false),
            '_marked' => new ScalarField(FieldType::Boolean),
        ],
        'properties' => [],
    ]);
});

it('resumes a synchronization according to its pause and connector state', function (
    bool $manually,
    bool $configured,
    bool $resume,
) {
    $sync = new Sync([
        'schema' => [new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id))],
    ])->forceFill(['status' => SyncStatus::Pause, 'is_paused' => $manually]);
    $sync->setRelation('connector', $configured ? new Connector(['name' => 'ferry-sink-test-sync']) : null);

    expect($sync->shouldResume($this->object))->toBe($resume);
})->with([
    'manual pause' => [true, true, false],
    'automatic pause' => [false, true, true],
    'never configured' => [false, false, false],
]);

it('reports removed and newly required destination fields', function () {
    $sync = new Sync([
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
            new FieldMapping('_marked', 'is_retired', new ScalarField(FieldType::Boolean)),
        ],
    ]);

    expect($sync->brokenColumns($this->object, customers_destination()->getSchema()))->toBe([
        'is_retired',
        'tax_number',
    ]);
});

it('reports every mapping as broken and refuses to resume when its destination is gone', function () {
    $sync = new Sync([
        'type' => DestinationType::Static,
        'destination' => 'gone',
        'schema' => [new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false))],
    ])->forceFill(['status' => SyncStatus::Pause, 'is_paused' => false]);
    $sync->setRelation('connector', new Connector(['name' => 'ferry-sink-test-sync']));

    expect($sync->brokenColumns($this->object))
        ->toBe(['name'])
        ->and($sync->hasValidSchema($this->object))
        ->toBeFalse()
        ->and($sync->shouldResume($this->object))
        ->toBeFalse();
});

it('ignores removed unused sources during structure review', function () {
    $object = new ConnectionMetadata(['system' => [], 'properties' => []]);
    $sync = new Sync(['schema' => [
        new FieldMapping(
            '_unused',
            null,
            new ScalarField(FieldType::Boolean),
        ),
    ]]);

    expect($sync->hasValidSchema($object))->toBeTrue();
});

it('uses directional numeric compatibility when saving and reviewing static synchronizations', function (
    ColumnType $source,
    ColumnType $target,
    bool $allowed,
) {
    $field = new NumberField(precision: 10, scale: $source === ColumnType::Integer ? 0 : 2, unsigned: false);
    $object = new ConnectionMetadata(['system' => ['amount' => $field], 'properties' => []]);
    $destination = new StaticDestination(
        'totals',
        'Totals',
        'totals',
        'id',
        Schema::object([
            'amount' => Schema::fromArray($target->jsonSchema())->title('Amount'),
        ])->required(['amount']),
    );
    $mappings = [new FieldMapping('amount', 'amount', $field)];

    if ($allowed) {
        $mappings = SyncSchema::fromBindings(
            $object,
            ['amount' => 'amount'],
            app(TransformRegistry::class),
            $destination,
        );
        SyncSchema::assertValid($mappings, $destination);
        expect($mappings[0]->type())->toBe($source)->and($mappings[0]->transforms)->toBe([]);
    } else {
        expect(fn () => SyncSchema::fromBindings(
            $object,
            ['amount' => 'amount'],
            app(TransformRegistry::class),
            $destination,
        ))
            ->toThrow(ValidationException::class);
        expect(fn () => SyncSchema::assertValid($mappings, $destination))->toThrow(ValidationException::class);
    }

    $sync = new Sync(['type' => DestinationType::Static, 'destination' => 'totals', 'schema' => $mappings]);
    expect($sync->brokenColumns($object, $destination->getSchema()))->toBe($allowed ? [] : ['amount']);
})->with([
    'integer to number' => [ColumnType::Integer, ColumnType::Decimal, true],
    'number to integer' => [ColumnType::Decimal, ColumnType::Integer, false],
    'integer to integer' => [ColumnType::Integer, ColumnType::Integer, true],
    'number to number' => [ColumnType::Decimal, ColumnType::Decimal, true],
]);

it('exposes default presentation for resolved destinations', function (
    DestinationType $type,
    string $destinationKey,
    string $label,
) {
    $factory = new DestinationFactory;
    $factory->register(customers_destination());
    $destination = $factory->resolve(new Sync(['type' => $type, 'destination' => $destinationKey, 'code' => 'demo']));

    expect($destination->getLabel())->toBe($label);
    expect($destination->getIcon())->toBeNull();
    expect($destination->getColor())->toBeNull();
})->with([
    'static' => [DestinationType::Static, 'customers', 'Customers'],
    'dynamic' => [DestinationType::Dynamic, 'existing_table', 'existing_table'],
]);

it('preserves configured static destination presentation', function () {
    $label = new HtmlString('<strong>Customers</strong>');
    $color = ['500' => '#2563eb', '600' => '#1d4ed8'];
    $destination = new StaticDestination(
        key: 'customers',
        label: $label,
        table: 'customers',
        primaryKey: 'id',
        schema: Schema::object(),
        icon: Heroicon::UserGroup,
        color: $color,
    );

    expect($destination->getLabel())->toBe($label);
    expect($destination->getIcon())->toBe(Heroicon::UserGroup);
    expect($destination->getColor())->toBe($color);
});

it('resolves label and schema callbacks in the current request locale', function () {
    $destination = new StaticDestination(
        key: 'localized',
        label: fn () => app()->getLocale(),
        table: 'localized',
        primaryKey: 'id',
        schema: fn () => Schema::object(['name' => Schema::string()->title(app()->getLocale())]),
    );
    app()->setLocale('ru');
    expect($destination->getLabel())->toBe('ru');
    expect($destination->getSchema()->properties()['name']->keyword('title'))->toBe('ru');

    app()->setLocale('en');

    expect($destination->getLabel())->toBe('en');
    expect($destination->getSchema()->properties()['name']->keyword('title'))->toBe('en');
});
