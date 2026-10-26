<?php

use Illuminate\Support\Facades\Lang;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\StructureItem;
use Tests\TestCase;

uses(TestCase::class);

it('falls back from the title to the label and then to the physical name', function (
    string $title,
    string $label,
    string $expected,
) {
    expect(new StructureItem(['name' => 'records', 'title' => $title, 'label' => $label])->displayTitle())
        ->toBe($expected);
})->with([
    'title' => ['Title', 'Label', 'Title'],
    'label' => ['', 'Label', 'Label'],
    'physical name' => ['', '', 'records'],
    'zero title' => ['0', 'Label', '0'],
    'zero label' => ['', '0', '0'],
]);

it('reads ready schemas by physical column name', function () {
    $item = new StructureItem([
        'key_column' => 'record_id',
        'schema' => JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'record_id' => ['type' => 'integer', 'title' => 'ID'],
                'display_name' => [
                    'type' => 'string',
                    'maxLength' => 80,
                    'title' => 'Name',
                    'description' => 'Public name',
                ],
            ],
        ]),
    ]);

    expect($item->field('display_name')->toArray())
        ->toBe(['type' => 'string', 'maxLength' => 80])
        ->and($item->mappableFields(withKey: false))
        ->toBe(['display_name' => [
            'type' => 'string',
            'maxLength' => 80,
            'title' => 'Name',
            'description' => 'Public name',
        ]])
        ->and($item->field('missing'))
        ->toBeNull();
});

it('resolves the full translation key before the title in the current interface locale', function () {
    $definition = [
        'type' => 'object',
        'properties' => [
            '_description' => [
                'type' => 'string',
                'title' => 'Fallback title',
                'x-ferry-trans' => 'phpinnacle-ferry::resources.replica.system_fields._description',
            ],
        ],
    ];
    $item = new StructureItem(['key_column' => 'id', 'schema' => JsonSchema::fromArray($definition)]);
    Lang::setLocale('en');
    $english = $item->mappableFields()['_description']['title'];
    expect($english)
        ->toBe(__('phpinnacle-ferry::resources.replica.system_fields._description'))
        ->not
        ->toBe('Fallback title')
        ->and($item->field('_description')->toArray())
        ->toBe(['type' => 'string']);

    Lang::setLocale('ru');

    expect($item->mappableFields()['_description']['title'])
        ->toBe(__('phpinnacle-ferry::resources.replica.system_fields._description'))
        ->not->toBe($english);

    $definition['properties']['_description'] = ['type' => 'boolean', 'title' => 'Updated title'];
    $item->schema = JsonSchema::fromArray($definition);

    expect($item->field('_description')->singleType())
        ->toBe('boolean')
        ->and($item->mappableFields()['_description']['title'])
        ->toBe('Updated title');
});
