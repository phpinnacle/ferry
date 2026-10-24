<?php

use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Forms\SchemaCompatibility;

it('collects each declared badge once across conjunctive branches', function () {
    $first = ['label' => 'First', 'color' => 'success'];
    $second = ['label' => 'Second', 'color' => 'warning'];
    $schema = JsonSchema::fromArray([
        'allOf' => [['x-badges' => [$first]], ['x-badges' => [$second]]],
    ]);

    expect($schema->badges())->toBe([$first, $second]);
});

it('resolves maximum string lengths across composed output schemas', function (array $definition, ?int $maximum) {
    expect(JsonSchema::fromArray($definition)->maxLength())->toBe($maximum);
})->with([
    'unbounded' => [['type' => 'string'], null],
    'bounded' => [['type' => 'string', 'maxLength' => 10], 10],
    'intersection' => [['allOf' => [['type' => 'string', 'maxLength' => 20], ['maxLength' => 10]]], 10],
    'nullable alternatives' => [['anyOf' => [['type' => 'string', 'maxLength' => 10], ['type' => 'null']]], 10],
    'bounded alternatives' => [
        ['oneOf' => [['type' => 'string', 'maxLength' => 10], ['type' => 'string', 'maxLength' => 20]]],
        20,
    ],
    'unbounded alternative' => [['anyOf' => [['type' => 'string', 'maxLength' => 10], ['type' => 'string']]], null],
]);

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
    'reference metadata' => [
        ['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true],
        'uuid',
        false,
        'string',
    ],
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
        ->toBe([['kind' => 'items', 'value' => '3', 'scope' => []]])
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

it('intersects numeric types in allOf without losing their integer constraint', function (array $definition) {
    $schema = JsonSchema::fromArray($definition);

    expect($schema->types())
        ->toBe(['integer'])
        ->and($schema->compatible(JsonSchema::fromArray(['type' => 'integer'])))
        ->toBeTrue()
        ->and($schema->compatible(JsonSchema::fromArray(['type' => 'number'])))
        ->toBeTrue()
        ->and($schema->compatible(JsonSchema::fromArray(['type' => 'string'])))
        ->toBeFalse()
        ->and(JsonSchema::fromArray(['type' => 'integer'])->compatible($schema))
        ->toBeTrue()
        ->and(JsonSchema::fromArray(['type' => 'number'])->compatible($schema))
        ->toBeFalse();
})->with([
    'number then integer' => [['allOf' => [['type' => 'number'], ['type' => 'integer']]]],
    'integer then number' => [['allOf' => [['type' => 'integer'], ['type' => 'number']]]],
    'parent number' => [['type' => 'number', 'allOf' => [['type' => 'integer']]]],
    'parent integer' => [['type' => 'integer', 'allOf' => [['type' => 'number']]]],
    'union narrowed to integer' => [['allOf' => [['type' => ['number', 'string']], ['type' => 'integer']]]],
    'nested intersection' => [['allOf' => [['type' => 'number'], ['allOf' => [['type' => 'integer']]]]]],
]);

it('preserves number and null alternatives in numeric intersections', function () {
    expect(JsonSchema::fromArray(['allOf' => [['type' => 'number'], ['type' => 'number']]])->types())
        ->toBe(['number'])
        ->and(
            JsonSchema::fromArray([
                'allOf' => [['type' => ['number', 'null']], ['type' => ['integer', 'null']]],
            ])->types(),
        )
        ->toEqualCanonicalizing(['integer', 'null']);
});

it('compares declared unions, nullability, formats and nested array types', function (
    array $source,
    array $target,
    bool $compatible,
) {
    expect(JsonSchema::fromArray($source)->compatible(JsonSchema::fromArray($target)))->toBe($compatible);
})->with([
    'matching scalar' => [['type' => 'string'], ['type' => 'string'], true],
    'reference metadata does not restrict UUID compatibility' => [
        ['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true],
        ['type' => 'string', 'format' => 'uuid'],
        true,
    ],
    'integer fits number' => [['type' => 'integer'], ['type' => 'number'], true],
    'number does not fit integer' => [['type' => 'number'], ['type' => 'integer'], false],
    'nullable integer fits nullable number' => [['type' => ['integer', 'null']], ['type' => ['number', 'null']], true],
    'numeric widening preserves nullability' => [['type' => ['integer', 'null']], ['type' => 'number'], false],
    'numeric widening in unions' => [['type' => ['integer', 'string']], ['type' => ['number', 'string']], true],
    'numeric widening does not accept other types' => [['type' => ['integer', 'boolean']], ['type' => 'number'], false],
    'integer array fits number array' => [
        ['type' => 'array', 'items' => ['type' => 'integer']],
        ['type' => 'array', 'items' => ['type' => 'number']],
        true,
    ],
    'number array does not fit integer array' => [
        ['type' => 'array', 'items' => ['type' => 'number']],
        ['type' => 'array', 'items' => ['type' => 'integer']],
        false,
    ],
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

it('uses property keys as technical names and titles as labels', function () {
    $items = JsonSchema::fromArray([
        'properties' => [
            'property-1' => ['title' => 'Property title'],
            '0' => [],
        ],
    ])->toMappingItems();

    expect($items[0]['id'])->toBe('property-1');
    expect($items[0]['name'])->toBe('property-1');
    expect($items[0]['label'])->toBe('Property title');
    expect($items[1])->toMatchArray(['id' => '0', 'name' => '0', 'label' => '0']);
});

it('requires source enum values to fit the destination enum', function (
    array $source,
    array $target,
    bool $compatible,
) {
    expect(JsonSchema::fromArray($source)->compatible(JsonSchema::fromArray($target)))->toBe($compatible);
})->with([
    'date into status' => [
        ['type' => 'string', 'format' => 'date'],
        ['type' => 'string', 'enum' => ['draft', 'active', 'blocked']],
        false,
    ],
    'unrestricted string' => [['type' => 'string'], ['type' => 'string', 'enum' => ['active']], false],
    'unknown source' => [[], ['enum' => ['active']], false],
    'matching enum' => [
        ['type' => 'string', 'enum' => ['draft', 'active']],
        ['type' => 'string', 'enum' => ['active', 'draft']],
        true,
    ],
    'subset' => [['enum' => ['active']], ['enum' => ['draft', 'active']], true],
    'superset' => [['enum' => ['draft', 'active']], ['enum' => ['active']], false],
    'disjoint' => [['enum' => ['blocked']], ['enum' => ['active']], false],
    'unrestricted destination' => [['type' => 'string', 'enum' => ['active']], ['type' => 'string'], true],
    'nullable subset' => [
        ['type' => ['string', 'null'], 'enum' => ['active', null]],
        ['type' => ['string', 'null'], 'enum' => ['draft', 'active', null]],
        true,
    ],
    'null outside destination enum' => [
        ['type' => ['string', 'null'], 'enum' => ['active', null]],
        ['type' => ['string', 'null'], 'enum' => ['active']],
        false,
    ],
    'numbers compare by value' => [['enum' => [1]], ['enum' => [1.0]], true],
    'integer enum fits number enum' => [
        ['type' => 'integer', 'enum' => [1]],
        ['type' => 'number', 'enum' => [1.0, 2.5]],
        true,
    ],
    'numeric widening preserves enum restrictions' => [
        ['type' => 'integer', 'enum' => [2]],
        ['type' => 'number', 'enum' => [1.0, 2.5]],
        false,
    ],
    'negative zero' => [['enum' => [0]], ['enum' => [-0.0]], true],
    'string is not number' => [['enum' => ['1']], ['enum' => [1]], false],
    'boolean is not number' => [['enum' => [false]], ['enum' => [0]], false],
    'integer precision' => [['enum' => [9_007_199_254_740_993]], ['enum' => [9_007_199_254_740_992.0]], false],
    'object property order' => [
        ['type' => 'object', 'enum' => [['a' => 1, 'b' => false]]],
        ['type' => 'object', 'enum' => [['b' => false, 'a' => 1.0]]],
        true,
    ],
    'object representation' => [['enum' => [(object) ['a' => 1]]], ['enum' => [['a' => 1]]], true],
    'empty object is not array' => [['enum' => [new stdClass]], ['enum' => [[]]], false],
    'array values' => [['type' => 'array', 'enum' => [[1, 'a']]], ['type' => 'array', 'enum' => [[1.0, 'a']]], true],
    'array order' => [['type' => 'array', 'enum' => [[1, 2]]], ['type' => 'array', 'enum' => [[2, 1]]], false],
    'nested value types' => [['enum' => [['value' => false]]], ['enum' => [['value' => 'false']]], false],
    'source intersection' => [
        ['type' => 'string', 'enum' => ['draft', 'active'], 'allOf' => [['enum' => ['active', 'blocked']]]],
        ['type' => 'string', 'enum' => ['active']],
        true,
    ],
    'target intersection' => [
        ['type' => 'string', 'enum' => ['draft']],
        ['type' => 'string', 'enum' => ['draft', 'active'], 'allOf' => [['enum' => ['active', 'blocked']]]],
        false,
    ],
    'source alternatives' => [
        ['anyOf' => [['type' => 'string', 'enum' => ['draft']], ['type' => 'string', 'enum' => ['active']]]],
        ['type' => 'string', 'enum' => ['draft', 'active']],
        true,
    ],
    'source alternative outside enum' => [
        ['anyOf' => [['type' => 'string', 'enum' => ['draft']], ['type' => 'string', 'enum' => ['blocked']]]],
        ['type' => 'string', 'enum' => ['draft', 'active']],
        false,
    ],
    'matching target alternative' => [
        ['type' => 'string', 'enum' => ['active']],
        ['type' => 'string', 'anyOf' => [['enum' => ['draft']], ['enum' => ['active', 'blocked']]]],
        true,
    ],
    'array item enum' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['active']]],
        false,
    ],
    'matching array item enum' => [
        ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['active']]],
        ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['active', 'draft']]],
        true,
    ],
]);

it('uses Jason reference projections without changing the serialized mapping schema', function () {
    $definition = [
        '$defs' => ['code' => ['type' => 'string', 'maxLength' => 5]],
        'properties' => ['code' => ['$ref' => '#/$defs/code', 'maxLength' => 10]],
    ];
    $schema = JsonSchema::fromArray($definition);
    expect($schema->properties()['code']->maxLength())->toBe(5)->and($schema->toArray())->toBe($definition);
});

it('rejects boolean mapping fields instead of changing them into unconstrained definitions', function () {
    $schema = JsonSchema::fromArray(['properties' => ['forbidden' => false]]);
    expect($schema->properties(...))->toThrow(InvalidArgumentException::class, 'Boolean schemas');
});
