<?php

use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Forms\SchemaCompatibility;

it('presents formats, enums, nullable values and nested arrays without changing logical types', function (
    array $definition,
    string $type,
    bool $nullable,
    string $logicalType,
) {
    $schema = JsonSchema::fromArray($definition);

    expect($schema->presentation())
        ->toMatchArray(['type' => $type, 'nullable' => $nullable])
        ->and($schema->label())
        ->toBe($logicalType);
})->with([
    'format' => [['type' => 'string', 'format' => 'uuid'], 'uuid', false, 'string'],
    'formatted alternatives' => [
        ['anyOf' => [['type' => 'string', 'format' => 'uuid'], ['type' => 'boolean']]],
        'uuid | boolean',
        false,
        'string | boolean',
    ],
    'nullable format' => [['type' => ['string', 'null'], 'format' => 'date'], 'date', true, 'string | null'],
    'enum' => [['type' => 'string', 'enum' => ['draft', 'active']], 'enum', false, 'string'],
    'null' => [['type' => 'null'], 'null', false, 'null'],
    'nested array' => [
        ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'number']]],
        'number[][]',
        false,
        'number[][]',
    ],
    'union items' => [
        ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
        '(integer | string)[]',
        false,
        '(integer | string)[]',
    ],
]);

it('shows constraint values with their item and composition scopes', function () {
    $presentation = JsonSchema::fromArray([
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => 10,
        'uniqueItems' => true,
        'items' => [
            'allOf' => [
                ['type' => 'string', 'minLength' => 0, 'maxLength' => 16],
                ['pattern' => '^[A-Z]+$'],
            ],
        ],
    ])->presentation();

    expect($presentation['constraints'])
        ->toBe([
            ['kind' => 'items', 'value' => '1–10', 'scope' => []],
            ['kind' => 'unique', 'value' => '', 'scope' => []],
        ])
        ->and($presentation['items'])
        ->toBe([
            [
                'scope' => ['items'],
                'type' => 'string',
                'nullable' => false,
                'constraints' => [
                    ['kind' => 'length', 'value' => '0–16', 'scope' => ['allOf 1']],
                    ['kind' => 'pattern', 'value' => '/^[A-Z]+$/', 'scope' => ['allOf 2']],
                ],
                'enum' => [],
            ],
        ]);
});

it('keeps each internal array level and its restrictions separate from the outer array', function () {
    $presentation = JsonSchema::fromArray([
        'type' => 'array',
        'maxItems' => 10,
        'items' => [
            'type' => 'array',
            'minItems' => 3,
            'maxItems' => 3,
            'items' => ['type' => 'number', 'minimum' => 0],
        ],
    ])->presentation();

    expect($presentation['constraints'])
        ->toBe([['kind' => 'items', 'value' => '≤ 10', 'scope' => []]])
        ->and(array_column($presentation['items'], 'type'))
        ->toBe(['number[]', 'number'])
        ->and($presentation['items'][0]['scope'])
        ->toBe(['items'])
        ->and($presentation['items'][0]['constraints'])
        ->toBe([['kind' => 'items', 'value' => '3–3', 'scope' => []]])
        ->and($presentation['items'][1]['scope'])
        ->toBe(['items', 'items'])
        ->and($presentation['items'][1]['constraints'])
        ->toBe([['kind' => 'range', 'value' => '≥ 0', 'scope' => []]]);
});

it('presents numeric limits and allowed values without treating them as connection rules', function () {
    $schema = JsonSchema::fromArray([
        'type' => 'number',
        'minimum' => 0,
        'exclusiveMaximum' => 100,
        'multipleOf' => 0.5,
    ]);

    expect($schema->presentation()['constraints'])
        ->toBe([
            ['kind' => 'range', 'value' => '≥ 0 · < 100', 'scope' => []],
            ['kind' => 'multiple', 'value' => '× 0.5', 'scope' => []],
        ])
        ->and($schema->compatible(JsonSchema::fromArray(['type' => 'number', 'maximum' => 10])))
        ->toBeTrue()
        ->and(JsonSchema::fromArray(['type' => 'string', 'enum' => ['active', '<draft>']])->presentation()['enum'])
        ->toBe(['"active"', '"<draft>"']);
});

it('compares declared unions, nullability, formats and nested array types', function (
    array $source,
    array $target,
    bool $compatible,
) {
    expect(JsonSchema::fromArray($source)->compatible(JsonSchema::fromArray($target)))->toBe($compatible);
})->with([
    'matching scalar' => [['type' => 'string'], ['type' => 'string'], true],
    'unknown source' => [[], ['type' => 'string'], true],
    'unknown target' => [['type' => 'string'], [], true],
    'nullable target' => [['type' => 'string'], ['type' => ['string', 'null']], true],
    'nullable source' => [['type' => ['string', 'null']], ['type' => 'string'], false],
    'all source alternatives must fit' => [
        ['anyOf' => [['type' => 'string'], ['type' => 'boolean']]],
        ['type' => 'string'],
        false,
    ],
    'one target alternative must fit' => [
        ['type' => 'boolean'],
        ['anyOf' => [['type' => 'string'], ['type' => 'boolean']]],
        true,
    ],
    'parent type constrains alternatives' => [
        ['type' => 'string', 'anyOf' => [['format' => 'uuid'], ['format' => 'email']]],
        ['type' => 'boolean'],
        false,
    ],
    'distinct formats' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string', 'format' => 'date-time'],
        false,
    ],
    'formatted string fits unrestricted string' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string'],
        true,
    ],
    'unrestricted string cannot guarantee format' => [
        ['type' => 'string'],
        ['type' => 'string', 'format' => 'uuid'],
        false,
    ],
    'matching format' => [
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => 'date'],
        true,
    ],
    'date is not date-time' => [
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'format' => 'date-time'],
        false,
    ],
    'longer source' => [['type' => 'string', 'maxLength' => 255], ['type' => 'string', 'maxLength' => 64], false],
    'shorter source' => [['type' => 'string', 'maxLength' => 64], ['type' => 'string', 'maxLength' => 255], true],
    'equal limit' => [['type' => 'string', 'maxLength' => 64], ['type' => 'string', 'maxLength' => 64], true],
    'unknown source length' => [['type' => 'string'], ['type' => 'string', 'maxLength' => 64], false],
    'unbounded destination' => [['type' => 'string', 'maxLength' => 64], ['type' => 'string'], true],
    'format does not imply length' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string', 'maxLength' => 64],
        false,
    ],
    'source minimum too low' => [['type' => 'string', 'minLength' => 2], ['type' => 'string', 'minLength' => 4], false],
    'source minimum fits' => [['type' => 'string', 'minLength' => 4], ['type' => 'string', 'minLength' => 2], true],
    'implicit source minimum' => [['type' => 'string'], ['type' => 'string', 'minLength' => 1], false],
    'empty string fits zero maximum' => [
        ['type' => 'string', 'maxLength' => 0],
        ['type' => 'string', 'maxLength' => 0],
        true,
    ],
    'implicit type still constrains strings' => [['type' => 'string', 'maxLength' => 255], ['maxLength' => 64], false],
    'string constraints do not affect booleans' => [
        ['type' => 'boolean'],
        ['type' => 'boolean', 'minLength' => 4, 'format' => 'uuid'],
        true,
    ],
    'intersect source bounds' => [
        ['type' => 'string', 'maxLength' => 255, 'allOf' => [['maxLength' => 64], ['minLength' => 4]]],
        ['type' => 'string', 'minLength' => 2, 'maxLength' => 64],
        true,
    ],
    'intersect destination bounds' => [
        ['type' => 'string', 'maxLength' => 255],
        ['type' => 'string', 'maxLength' => 255, 'allOf' => [['maxLength' => 64]]],
        false,
    ],
    'every source length alternative must fit' => [
        ['type' => 'string', 'anyOf' => [['maxLength' => 32], ['maxLength' => 255]]],
        ['type' => 'string', 'maxLength' => 64],
        false,
    ],
    'nested source alternatives retain sibling constraints' => [
        ['allOf' => [['type' => 'string'], ['anyOf' => [['maxLength' => 32], ['maxLength' => 255]]]]],
        ['type' => 'string', 'maxLength' => 64],
        false,
    ],
    'one destination length alternative fits' => [
        ['type' => 'string', 'maxLength' => 64],
        ['type' => 'string', 'oneOf' => [['maxLength' => 32], ['minLength' => 0, 'maxLength' => 255]]],
        true,
    ],
    'array item limits' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255]],
        ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64]],
        false,
    ],
    'matching arrays' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'array', 'items' => ['type' => 'string']],
        true,
    ],
    'all composed array item alternatives must fit' => [
        ['type' => 'array', 'anyOf' => [['items' => ['type' => 'string']], ['items' => ['type' => 'boolean']]]],
        ['type' => 'array', 'items' => ['type' => 'string']],
        false,
    ],
    'different array items' => [
        ['type' => 'array', 'items' => ['type' => 'boolean']],
        ['type' => 'array', 'items' => ['type' => 'string']],
        false,
    ],
    'mixed types retain array item types' => [
        ['type' => ['string', 'array'], 'items' => ['type' => 'string']],
        ['type' => ['string', 'array'], 'items' => ['type' => 'boolean']],
        false,
    ],
    'mixed types retain array item bounds' => [
        ['type' => ['string', 'array'], 'items' => ['type' => 'string', 'maxLength' => 255]],
        ['type' => ['string', 'array'], 'items' => ['type' => 'string', 'maxLength' => 64]],
        false,
    ],
    'mixed types accept wider array items' => [
        ['type' => ['string', 'array'], 'items' => ['type' => 'string', 'maxLength' => 64]],
        ['type' => ['string', 'array'], 'items' => ['type' => 'string', 'maxLength' => 255]],
        true,
    ],
]);

it('configures format and length checks independently throughout nested schemas', function () {
    $source = JsonSchema::fromArray([
        'type' => 'array',
        'items' => [
            'type' => 'string',
            'format' => 'uuid',
            'maxLength' => 255,
        ],
    ]);
    $target = JsonSchema::fromArray([
        'type' => 'array',
        'items' => [
            'type' => 'string',
            'format' => 'date',
            'maxLength' => 64,
        ],
    ]);
    $defaults = SchemaCompatibility::make();

    expect($source->compatible($target, $defaults->formats(false)))->toBeFalse();
    expect($source->compatible($target, $defaults->stringLengths(false)))->toBeFalse();
    expect($source->compatible($target, $defaults->formats(false)->stringLengths(false)))->toBeTrue();
    expect($source->compatible($target, $defaults))->toBeFalse();
    expect(JsonSchema::fromArray(['type' => 'boolean'])->compatible(
        JsonSchema::fromArray(['type' => 'string']),
        $defaults->formats(false)->stringLengths(false),
    ))->toBeFalse();
});

it('projects composed property metadata and required names', function () {
    $schema = [
        'allOf' => [
            ['properties' => ['name' => ['type' => 'string', 'title' => 'Name']], 'required' => ['name']],
            [
                'properties' => [
                    'name' => ['description' => 'Customer name'],
                    'contacts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ],
    ];
    $properties = JsonSchema::fromArray($schema)->properties();

    expect(JsonSchema::fromArray($schema)->required())->toBe(['name']);
    expect($properties['name']->annotation('title'))->toBe('Name');
    expect($properties['name']->annotation('description'))->toBe('Customer name');
    expect($properties['name']->label())->toBe('string');
    expect($properties['contacts']->label())->toBe('string[]');
    expect(JsonSchema::fromArray(['type' => ['array', 'null'], 'items' => ['type' => 'string']])->items()->label())
        ->toBe('string');
});
