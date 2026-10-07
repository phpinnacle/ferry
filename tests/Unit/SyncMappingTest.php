<?php

use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Rosetta\Enums\FieldType;
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
        'static_destination' => 'customers',
        'schema' => [
            new FieldMapping('_description', 'name', new StringField(length: 100, fixed: false)),
            new FieldMapping('_marked', 'is_retired', new ScalarField(FieldType::Boolean)),
        ],
    ]);

    expect($sync->brokenColumns($this->object, customers_destination()->fields))->toBe(['is_retired', 'tax_number']);
});

it('reports every mapping as broken and refuses to resume when its destination is gone', function () {
    $sync = new Sync([
        'static_destination' => 'gone',
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
