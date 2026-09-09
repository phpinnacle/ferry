<?php

use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;
use Tests\TestCase;

uses(TestCase::class);

it('maps typed Rosetta fields to destination column types', function (Field $field, ?ColumnType $type) {
    expect(ColumnType::fromField($field))->toBe($type);
})->with([
    'limited string' => [new StringField(length: 50, fixed: false), ColumnType::String],
    'unlimited string' => [new StringField(length: null, fixed: false), ColumnType::String],
    'reference' => [new ReferenceField('catalog.products'), ColumnType::Reference],
    'whole number' => [new NumberField(precision: 10, scale: 0, unsigned: false), ColumnType::Integer],
    'number without scale' => [new NumberField(precision: null, scale: null, unsigned: false), ColumnType::Integer],
    'fractional number' => [new NumberField(precision: 15, scale: 2, unsigned: false), ColumnType::Decimal],
    'flag' => [new ScalarField(FieldType::Boolean), ColumnType::Boolean],
    'date' => [new ScalarField(FieldType::Date), ColumnType::DateTime],
    'time' => [new ScalarField(FieldType::Time), ColumnType::DateTime],
    'date and time' => [new ScalarField(FieldType::DateTime), ColumnType::DateTime],
    'identifier' => [new ScalarField(FieldType::Id), ColumnType::Reference],
    'uuid' => [new ScalarField(FieldType::Uuid), ColumnType::Reference],
    'binary' => [new ScalarField(FieldType::Binary), null],
    'unknown' => [new ScalarField(FieldType::Unknown), null],
    'undefined' => [new ScalarField(FieldType::Undefined), null],
    'union' => [new UnionField([new StringField(length: null, fixed: false)]), null],
]);

it('resolves a translated label for each column type', function () {
    foreach (ColumnType::cases() as $case) {
        expect($case->getLabel())->toBe(__('phpinnacle-ferry::enums.column_type.' . $case->value));
    }
});
