<?php

use Illuminate\Support\Facades\Lang;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
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

$makeObject = fn (array $attributes = []) => new ConnectionMetadata([
    'external_id' => 'object-0',
    'name' => '_reference0',
    'code' => 1,
    'kind' => MetadataKind::Reference,
    'label' => 'Label',
    'title' => 'Title',
    'system' => [],
    'properties' => [],
    'values' => [],
    ...$attributes,
]);

$makeProperty = fn (string $id, string $name, string $label, string $title, object $field) => new MetadataProperty(
    id: $id,
    name: $name,
    code: 1,
    kind: PropertyKind::Field,
    label: $label,
    title: $title,
    field: $field,
);

it('falls back from the title to the label and then to the physical name', function () use ($makeObject) {
    expect($makeObject()->displayTitle())
        ->toBe('Title')
        ->and($makeObject(['title' => ''])->displayTitle())
        ->toBe('Label')
        ->and($makeObject(['title' => '', 'label' => ''])->displayTitle())
        ->toBe('_reference0');
});

it('refreshes field lookups after metadata or locale changes on the same model', function () use (
    $makeObject,
    $makeProperty,
) {
    $object = $makeObject([
        'system' => ['_description' => new StringField(100, false)],
        'properties' => [$makeProperty(
            id: 'code',
            name: '_fld1',
            label: 'Code',
            title: 'Code',
            field: new StringField(10, false),
        )],
    ]);
    $english = $object->mappableFields()[0]['title'];
    expect($object->physicalColumn('code'))->toBe('_fld1');
    $object->properties = [$makeProperty(
        id: 'code',
        name: '_fld2',
        label: 'Enabled',
        title: 'Enabled',
        field: new ScalarField(FieldType::Boolean),
    )];
    expect($object->physicalColumn('code'))
        ->toBe('_fld2')
        ->and($object->field('code'))
        ->toEqual(new ScalarField(FieldType::Boolean));
    $object->system = ['_description' => new StringField(200, false)];
    expect($object->field('_description'))->toEqual(new StringField(200, false));
    Lang::setLocale('ru');
    expect($object->mappableFields()[0]['title'])
        ->toBe(__('phpinnacle-ferry::resources.sync.system_fields._description'))
        ->not->toBe($english);
});

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

    expect($object->mappableFields())
        ->toBe([
            [
                'source' => '_idrref',
                'title' => __('phpinnacle-ferry::resources.sync.system_fields._idrref'),
                'description' => null,
                'physical' => '_idrref',
                'type' => ColumnType::Reference,
            ],
            [
                'source' => '_description',
                'title' => __('phpinnacle-ferry::resources.sync.system_fields._description'),
                'description' => null,
                'physical' => '_description',
                'type' => ColumnType::String,
            ],
            [
                'source' => '_document12_idrref',
                'title' => '_document12_idrref',
                'description' => null,
                'physical' => '_document12_idrref',
                'type' => ColumnType::Reference,
            ],
            [
                'source' => 'property-1',
                'title' => 'Title 1',
                'description' => null,
                'physical' => '_fld1',
                'type' => ColumnType::String,
            ],
            [
                'source' => 'property-2',
                'title' => 'Label 2',
                'description' => null,
                'physical' => '_fld2',
                'type' => ColumnType::String,
            ],
            [
                'source' => 'property-3',
                'title' => '_fld3',
                'description' => null,
                'physical' => '_fld3',
                'type' => ColumnType::String,
            ],
        ])
        ->and(array_column($object->mappableFields(withKey: false), 'source'))
        ->not->toContain('_idrref');
});

it('resolves a single reference to its physical column without changing its stable source id', function () use (
    $makeObject,
    $makeProperty,
) {
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

    expect($object->mappableFields())
        ->toBe([
            [
                'source' => '_parentidrref',
                'title' => __('phpinnacle-ferry::resources.sync.system_fields._parentidrref'),
                'description' => null,
                'physical' => '_parentidrref',
                'type' => ColumnType::Reference,
            ],
            [
                'source' => 'supplier-id',
                'title' => 'Supplier',
                'description' => null,
                'physical' => '_fld17rref',
                'type' => ColumnType::Reference,
            ],
        ])
        ->and($object->physicalColumn('supplier-id'))
        ->toBe('_fld17rref')
        ->and($object->field('supplier-id'))
        ->toEqual($reference);
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

    expect(array_column($object->mappableFields(), 'physical', 'source'))
        ->toBe([
            'supplier-id:type' => '_fld17_type',
            'supplier-id:rtref' => '_fld17_rtref',
            'supplier-id:rrref' => '_fld17_rrref',
        ])
        ->and($object->field('supplier-id:type'))
        ->toEqual(new StringField(2, true))
        ->and($object->field('supplier-id:rtref'))
        ->toEqual(new StringField(8, true))
        ->and($object->field('supplier-id:rrref'))
        ->toEqual(new ScalarField(FieldType::Uuid))
        ->and($object->physicalColumn('supplier-id:rrref'))
        ->toBe('_fld17_rrref');
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

    expect(array_column($object->mappableFields(), 'physical', 'source'))
        ->toBe([
            'value-id:type' => '_fld18_type',
            'value-id:l' => '_fld18_l',
            'value-id:n' => '_fld18_n',
            'value-id:t' => '_fld18_t',
            'value-id:s' => '_fld18_s',
            'value-id:rrref' => '_fld18_rrref',
        ])
        ->and($object->field('value-id:n'))
        ->toEqual(new NumberField(12, 2, false))
        ->and($object->field('value-id:s'))
        ->toEqual(new StringField(100, false))
        ->and($object->field('value-id:rrref'))
        ->toEqual(new ReferenceField('catalog-a'));
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

    expect(array_column($object->mappableFields(), 'physical'))
        ->toBe([
            '_fld19_type',
            '_fld19_s',
            '_fld19_rtref',
            '_fld19_rrref',
        ])
        ->and($object->field('value-id:s'))
        ->toEqual(new StringField(30, false));
});
