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

$makeObject = function () {
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
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);
};

it('validates the bindings against a static destination', function () use ($makeObject) {
    $builder = app(SyncSchemaBuilder::class);
    $object = $makeObject();

    $mappings = $builder->fromBindings(
        $object,
        ['_description' => 'name', '_code' => 'tax_number'],
        new FakeCustomersDestination,
    );

    expect($mappings)
        ->toHaveCount(2)
        ->and($mappings[0]->column)
        ->toBe('name')
        ->and(fn () => $builder->fromBindings($object, ['_description' => 'name'], new FakeCustomersDestination))
        ->toThrow(ValidationException::class)
        ->and(fn () => $builder->fromBindings(
            $object,
            ['_description' => 'name', '_code' => 'tax_number', '_idrref' => 'is_active'],
            new FakeCustomersDestination,
        ))
        ->toThrow(ValidationException::class);
});
