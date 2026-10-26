<?php

use PHPinnacle\Ferry\Services\Rosetta\StructureConverter;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Data\MetadataDefinition;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;
use Tests\TestCase;

uses(TestCase::class);

$makeObject = fn (array $attributes = []) => new StructureConverter()->item(new MetadataDefinition(...[
    'id' => 'object-0',
    'name' => '_reference0',
    'code' => 1,
    'kind' => MetadataKind::Reference,
    'label' => 'Label',
    'title' => 'Title',
    'system' => [],
    'properties' => [],
    'values' => [],
    ...$attributes,
]));

$makeProperty = fn (string $id, string $name, string $label, string $title, object $field) => new MetadataProperty(
    id: $id,
    name: $name,
    code: 1,
    kind: PropertyKind::Field,
    label: $label,
    title: $title,
    field: $field,
);

it('lists mappable fields with translated system titles and property titles', function () use (
    $makeObject,
    $makeProperty,
) {
    $string = new StringField(length: 10, fixed: false);

    $object = $makeObject([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_description' => $string,
            '_keyfield' => new ScalarField(FieldType::Binary),
            '_document12_idrref' => new ReferenceField('document-12'),
        ],
        'properties' => [
            $makeProperty(id: 'property-1', name: '_fld1', label: 'Label 1', title: 'Title 1', field: $string),
            $makeProperty(id: 'property-2', name: '_fld2', label: 'Label 2', title: '', field: $string),
            $makeProperty(id: 'property-3', name: '_fld3', label: '', title: '', field: $string),
            $makeProperty(
                id: 'property-4',
                name: '_fld4',
                label: 'Label 4',
                title: 'Title 4',
                field: new ScalarField(FieldType::Binary),
            ),
        ],
    ]);

    $object->primary_key = ['_idrref'];

    expect(array_map(fn (array $field) => $field['title'], $object->mappableFields()))
        ->toBe([
            '_idrref' => __('phpinnacle-ferry::resources.replica.system_fields._idrref'),
            '_description' => __('phpinnacle-ferry::resources.replica.system_fields._description'),
            '_document12_idrref' => '_document12_idrref',
            '_fld1' => 'Title 1',
            '_fld2' => 'Label 2',
            '_fld3' => '_fld3',
        ])
        ->and(array_keys($object->mappableFields(withKey: false)))
        ->not->toContain('_idrref');
});

it('stores a single reference under its physical column name', function () use ($makeObject, $makeProperty) {
    $reference = new ReferenceField('catalog-type');
    $object = $makeObject([
        'system' => ['_parentidrref' => $reference],
        'properties' => [$makeProperty(
            id: 'supplier-id',
            name: '_fld17',
            label: 'Supplier',
            title: 'Supplier',
            field: $reference,
        )],
    ]);

    expect(array_map(fn (array $field) => $field['title'], $object->mappableFields()))
        ->toBe([
            '_parentidrref' => __('phpinnacle-ferry::resources.replica.system_fields._parentidrref'),
            '_fld17rref' => 'Supplier',
        ])
        ->and($object->field('_fld17rref')->toArray())
        ->toEqual([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
});

it('exposes independent scalar sources for every column of a composite reference', function () use (
    $makeObject,
    $makeProperty,
) {
    $object = $makeObject(['properties' => [$makeProperty(
        id: 'supplier-id',
        name: '_fld17',
        label: 'Supplier',
        title: 'Supplier',
        field: new UnionField([
            new ReferenceField('catalog-a'),
            new ReferenceField('catalog-b'),
        ]),
    )]]);

    expect(array_keys($object->mappableFields()))
        ->toBe([
            '_fld17_type',
            '_fld17_rtref',
            '_fld17_rrref',
        ])
        ->and($object->field('_fld17_type')->toArray())
        ->toEqual(['type' => 'string', 'maxLength' => 2, 'minLength' => 2])
        ->and($object->field('_fld17_rtref')->toArray())
        ->toEqual(['type' => 'string', 'maxLength' => 8, 'minLength' => 8])
        ->and($object->field('_fld17_rrref')->toArray())
        ->toEqual([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
});

it('exposes mixed composite value columns and omits the reference type code for a single target', function () use (
    $makeObject,
    $makeProperty,
) {
    $object = $makeObject(['properties' => [$makeProperty(
        id: 'value-id',
        name: '_fld18',
        label: 'Value',
        title: 'Value',
        field: new UnionField([
            new ScalarField(FieldType::Boolean),
            new NumberField(12, 2, false),
            new ScalarField(FieldType::DateTime),
            new StringField(100, false),
            new ReferenceField('catalog-a'),
        ]),
    )]]);

    expect(array_keys($object->mappableFields()))
        ->toBe([
            '_fld18_type',
            '_fld18_l',
            '_fld18_n',
            '_fld18_t',
            '_fld18_s',
            '_fld18_rrref',
        ])
        ->and($object->field('_fld18_n')->toArray())
        ->toEqual([
            'type' => 'number',
            'x-ferry-precision' => 12,
            'x-ferry-scale' => 2,
            'multipleOf' => 0.01,
            'exclusiveMinimum' => -10_000_000_000,
            'exclusiveMaximum' => 10_000_000_000,
        ])
        ->and($object->field('_fld18_s')->toArray())
        ->toEqual(['type' => 'string', 'maxLength' => 100])
        ->and($object->field('_fld18_rrref')->toArray())
        ->toEqual([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]);
});

it('expands references inside nested type definitions without duplicating their physical columns', function () use (
    $makeObject,
    $makeProperty,
) {
    $object = $makeObject(['properties' => [$makeProperty(
        id: 'value-id',
        name: '_fld19',
        label: 'Value',
        title: 'Value',
        field: new UnionField([
            new StringField(30, false),
            new UnionField([new ReferenceField('catalog-a'), new ReferenceField('catalog-b')]),
        ]),
    )]]);

    expect(array_keys($object->mappableFields()))
        ->toBe([
            '_fld19_type',
            '_fld19_s',
            '_fld19_rtref',
            '_fld19_rrref',
        ])
        ->and($object->field('_fld19_s')->toArray())
        ->toEqual(['type' => 'string', 'maxLength' => 30]);
});

it('projects Rosetta scalar types into JSON Schema', function (Field $field, ?string $type) {
    expect(
        new StructureConverter()
            ->field($field)
            ->singleType(),
    )
        ->toBe($type);
})->with([
    'limited string' => [new StringField(length: 50, fixed: false), 'string'],
    'unlimited string' => [new StringField(length: null, fixed: false), 'string'],
    'reference' => [new ReferenceField('catalog.products'), 'string'],
    'whole number' => [new NumberField(precision: 10, scale: 0, unsigned: false), 'integer'],
    'number without scale' => [new NumberField(precision: null, scale: null, unsigned: false), 'integer'],
    'fractional number' => [new NumberField(precision: 15, scale: 2, unsigned: false), 'number'],
    'flag' => [new ScalarField(FieldType::Boolean), 'boolean'],
    'date' => [new ScalarField(FieldType::Date), 'string'],
    'time' => [new ScalarField(FieldType::Time), 'string'],
    'date and time' => [new ScalarField(FieldType::DateTime), 'string'],
    'identifier' => [new ScalarField(FieldType::Id), 'string'],
    'uuid' => [new ScalarField(FieldType::Uuid), 'string'],
    'binary' => [new ScalarField(FieldType::Binary), null],
    'unknown' => [new ScalarField(FieldType::Unknown), null],
    'undefined' => [new ScalarField(FieldType::Undefined), null],
    'union' => [new UnionField([new StringField(length: null, fixed: false)]), null],
]);

it('projects available Rosetta constraints without rounding numeric metadata', function (
    Field $field,
    array $expected,
) {
    expect(
        new StructureConverter()
            ->field($field)
            ->toArray(),
    )
        ->toEqual($expected);
})->with([
    'ordinary UUID' => [
        new ScalarField(FieldType::Uuid),
        ['type' => 'string', 'format' => 'uuid', 'minLength' => 36, 'maxLength' => 36],
    ],
    'variable string' => [new StringField(64, false), ['type' => 'string', 'maxLength' => 64]],
    'fixed string' => [new StringField(8, true), ['type' => 'string', 'maxLength' => 8, 'minLength' => 8]],
    'unlimited string' => [new StringField(0, false), ['type' => 'string']],
    'unknown string length' => [new StringField(null, false), ['type' => 'string']],
    'signed decimal' => [
        new NumberField(5, 2, false),
        [
            'type' => 'number',
            'x-ferry-precision' => 5,
            'x-ferry-scale' => 2,
            'multipleOf' => 0.01,
            'exclusiveMinimum' => -1000,
            'exclusiveMaximum' => 1000,
        ],
    ],
    'unsigned decimal' => [
        new NumberField(5, 2, true),
        [
            'type' => 'number',
            'x-ferry-precision' => 5,
            'x-ferry-scale' => 2,
            'minimum' => 0,
            'multipleOf' => 0.01,
            'exclusiveMaximum' => 1000,
        ],
    ],
    'fractional digits only' => [
        new NumberField(3, 3, false),
        [
            'type' => 'number',
            'x-ferry-precision' => 3,
            'x-ferry-scale' => 3,
            'multipleOf' => 0.001,
            'exclusiveMinimum' => -1,
            'exclusiveMaximum' => 1,
        ],
    ],
    'large decimal' => [
        new NumberField(20, 2, false),
        [
            'type' => 'number',
            'x-ferry-precision' => 20,
            'x-ferry-scale' => 2,
            'multipleOf' => 0.01,
            'exclusiveMinimum' => -1_000_000_000_000_000_000,
            'exclusiveMaximum' => 1_000_000_000_000_000_000,
        ],
    ],
    'integer' => [
        new NumberField(4, 0, true),
        [
            'type' => 'integer',
            'x-ferry-precision' => 4,
            'x-ferry-scale' => 0,
            'minimum' => 0,
            'exclusiveMaximum' => 10_000,
        ],
    ],
    'unknown dimensions' => [new NumberField(null, null, true), ['type' => 'integer', 'minimum' => 0]],
    'unknown scale' => [new NumberField(10, null, false), ['type' => 'integer', 'x-ferry-precision' => 10]],
    'unknown precision' => [
        new NumberField(null, 2, false),
        ['type' => 'number', 'x-ferry-scale' => 2, 'multipleOf' => 0.01],
    ],
]);

it('prepares source ordering without exposing Rosetta kinds to the interface', function (
    MetadataKind $kind,
    string $title,
    int $priority,
) use ($makeObject) {
    expect($makeObject(['kind' => $kind, 'title' => $title])->priority)->toBe($priority);
})->with([
    'catalog' => [MetadataKind::Reference, 'Catalog', 0],
    'document' => [MetadataKind::Document, 'Document', 1],
    'obsolete' => [MetadataKind::Reference, '(Obsolete) Catalog', 2],
]);
