<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

function static_destination_object(): ConnectionMetadata
{
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    return ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_marked' => new ScalarField(FieldType::Boolean),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);
}

it('maps source fields to compatible static destination fields', function () {
    $mappings = app(SyncSchemaBuilder::class)->build(
        static_destination_object(),
        [
            ['source' => '_description', 'column' => 'name'],
            ['source' => '_code', 'column' => 'tax_number'],
        ],
        new FakeCustomersDestination,
    );

    expect($mappings)
        ->toHaveCount(2)
        ->and($mappings[0]->column)
        ->toBe('name');
});

it('rejects a schema missing a required destination field', function () {
    expect(
        fn () => app(SyncSchemaBuilder::class)->build(
            static_destination_object(),
            [
                ['source' => '_description', 'column' => 'name'],
            ],
            new FakeCustomersDestination,
        ),
    )
        ->toThrow(ValidationException::class);
});

it('rejects mapping to a field not declared by the destination', function () {
    expect(
        fn () => app(SyncSchemaBuilder::class)->build(
            static_destination_object(),
            [
                ['source' => '_description', 'column' => 'unknown'],
            ],
            new FakeCustomersDestination,
        ),
    )
        ->toThrow(ValidationException::class);
});

it('rejects mapping with an incompatible destination field type', function () {
    expect(
        fn () => app(SyncSchemaBuilder::class)->build(
            static_destination_object(),
            [
                ['source' => '_description', 'column' => 'is_active'],
            ],
            new FakeCustomersDestination,
        ),
    )
        ->toThrow(ValidationException::class);
});

it('rejects mapping the source key field because it always feeds the destination primary key', function () {
    expect(
        fn () => app(SyncSchemaBuilder::class)->build(
            static_destination_object(),
            [
                ['source' => '_idrref', 'column' => 'name'],
                ['source' => '_code', 'column' => 'tax_number'],
            ],
            new FakeCustomersDestination,
        ),
    )
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.key_field_not_mappable', [
            'field' => '_idrref',
        ]));
});
