<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use Tests\TestCase;

require_once __DIR__ . '/../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    $this->object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_marked' => new ScalarField(FieldType::Boolean),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
    ]);
});

it('builds dynamic mappings directly from source to column bindings', function () {
    $mappings = SyncSchema::fromBindings($this->object, ['_idrref' => 'external_id', '_description' => 'title']);

    expect(array_column($mappings, 'column', 'source'))
        ->toBe(['_idrref' => 'external_id', '_description' => 'title'])
        ->and($mappings[0]->field)
        ->toEqual(new ScalarField(FieldType::Id))
        ->and($mappings[1]->field)
        ->toEqual(new StringField(length: 100, fixed: false));
});

it('maps source fields to compatible static destination fields', function () {
    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name', '_code' => 'tax_number'],
        new FakeCustomersDestination,
    );

    expect(array_column($mappings, 'column', 'source'))
        ->toBe(['_description' => 'name', '_code' => 'tax_number']);
});

it('rejects bindings that do not match source or destination declarations', function (
    array $bindings,
    bool $static,
    string $error,
    string $field,
) {
    expect(fn () => SyncSchema::fromBindings(
        $this->object,
        $bindings,
        $static ? new FakeCustomersDestination : null,
    ))
        ->toThrow(
            ValidationException::class,
            __('phpinnacle-ferry::resources.sync.errors.' . $error, ['field' => $field]),
        );
})->with([
    'unknown source' => [['missing' => 'title'], false, 'unknown_field', 'missing'],
    'required destination' => [['_description' => 'name'], true, 'required_destination_field_missing', 'Tax Number'],
    'unknown destination' => [['_description' => 'unknown'], true, 'unknown_destination_field', 'unknown'],
    'incompatible destination' => [
        ['_description' => 'is_active'],
        true,
        'incompatible_destination_field',
        'Is Active',
    ],
    'source key' => [['_idrref' => 'name', '_code' => 'tax_number'], true, 'key_field_not_mappable', '_idrref'],
]);
