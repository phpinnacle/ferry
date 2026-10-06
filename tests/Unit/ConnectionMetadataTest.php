<?php

use Illuminate\Support\Facades\Lang;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
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
                'physical' => '_idrref',
                'type' => ColumnType::Reference,
            ],
            [
                'source' => '_description',
                'title' => __('phpinnacle-ferry::resources.sync.system_fields._description'),
                'physical' => '_description',
                'type' => ColumnType::String,
            ],
            [
                'source' => '_document12_idrref',
                'title' => '_document12_idrref',
                'physical' => '_document12_idrref',
                'type' => ColumnType::Reference,
            ],
            ['source' => 'property-1', 'title' => 'Title 1', 'physical' => '_fld1', 'type' => ColumnType::String],
            ['source' => 'property-2', 'title' => 'Label 2', 'physical' => '_fld2', 'type' => ColumnType::String],
            ['source' => 'property-3', 'title' => '_fld3', 'physical' => '_fld3', 'type' => ColumnType::String],
        ])
        ->and(array_column($object->mappableFields(withKey: false), 'source'))
        ->not->toContain('_idrref');
});

it('translates every mappable system field without locale fallback', function (string $locale) {
    expect(Lang::get('phpinnacle-ferry::resources.sync.system_fields', [], $locale, false))->toHaveKeys([
        '_idrref',
        '_version',
        '_predefinedid',
        '_description',
        '_code',
        '_marked',
        '_folder',
        '_parentidrref',
        '_owneridrref',
        '_date_time',
        '_numberprefix',
        '_number',
        '_posted',
        '_period',
        '_lineno',
        '_active',
        '_recordkind',
        '_enumorder',
    ]);
})->with(['en', 'ru']);
