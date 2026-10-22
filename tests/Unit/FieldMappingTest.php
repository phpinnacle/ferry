<?php

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPinnacle\Ferry\Forms\FieldMapping;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Forms\SchemaCompatibility;
use PHPinnacle\Ferry\Tests\Fixtures\CustomerMappingField;
use PHPinnacle\Ferry\Tests\Fixtures\MappingState;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/CustomerMappingField.php';

require_once __DIR__ . '/../Fixtures/MappingState.php';

uses(TestCase::class);

dataset('field mapping mappingValues', [
    'empty mapping' => [[], true],
    'source entry must declare its kind' => [['title' => 'name'], false],
    'missing kind' => [['title' => ['source' => 'name']], false],
    'unknown kind' => [['title' => ['kind' => 'other', 'source' => 'name']], false],
    'unexpected value on source' => [['title' => [...MappingState::source('name'), 'value' => 'x']], false],
    'valid mapping' => [['title' => MappingState::source('name')], true],
    'source reused without permission' => [
        ['title' => MappingState::source('name'), 'contact' => MappingState::source('name')],
        false,
    ],
    'unknown target' => [['missing' => MappingState::source('name')], false],
    'unknown source' => [['title' => MappingState::source('missing')], false],
    'numeric source' => [['title' => 0], false],
    'nested value' => [['title' => ['name']], false],
    'scalar mapping' => ['name', false],
    'sequential mapping' => [['name', 'email'], false],
]);

dataset('field mapping typePairs', [
    'equal' => ['string', 'string', true],
    'different' => ['string', 'boolean', false],
    'untyped source' => [null, 'boolean', true],
    'untyped destination' => ['string', null, true],
    'both untyped' => [null, null, true],
]);

dataset('field mapping orientedMappingValues', [
    'forward multiple sources' => [
        false,
        ['payload' => [MappingState::source('id'), MappingState::source('name')]],
        true,
    ],
    'forward one source still uses a list' => [false, ['payload' => [MappingState::source('id')]], true],
    'forward single destination' => [false, ['enabled' => MappingState::source('active')], true],
    'forward whole value for item-only source' => [false, ['payload' => MappingState::source('id')], false],
    'forward list for single destination' => [false, ['enabled' => [MappingState::source('active')]], false],
    'forward empty list' => [false, ['payload' => []], false],
    'forward associative list' => [false, ['payload' => ['field' => 'id']], false],
    'forward nested list' => [false, ['payload' => [[MappingState::source('id')]]], false],
    'forward numeric source' => [
        false,
        ['payload' => [['kind' => 'source', 'source' => 42]]],
        false,
    ],
    'forward duplicate within list' => [
        false,
        ['payload' => [MappingState::source('id'), MappingState::source('id')]],
        false,
    ],
    'forward reused source across destinations' => [
        false,
        ['payload' => [MappingState::source('id')], 'enabled' => MappingState::source('id')],
        false,
    ],
    'forward unknown source in list' => [false, ['payload' => [MappingState::source('unknown')]], false],
    'inverse multiple sources' => [
        true,
        ['payload' => [MappingState::source('id'), MappingState::source('name')]],
        true,
    ],
    'inverse one source' => [true, ['payload' => [MappingState::source('id')]], true],
    'inverse single destination' => [true, ['enabled' => MappingState::source('active')], true],
    'inverse reused source' => [
        true,
        ['payload' => [MappingState::source('active')], 'enabled' => MappingState::source('active')],
        false,
    ],
    'inverse list for single destination' => [true, ['enabled' => [MappingState::source('active')]], false],
    'inverse numeric source' => [true, ['enabled' => ['kind' => 'source', 'source' => 42]], false],
    'inverse unknown source' => [true, ['enabled' => MappingState::source('unknown')], false],
    'inverse unknown destination' => [true, ['unknown' => MappingState::source('id')], false],
    'inverse scalar state' => [true, 'payload', false],
]);
it('oriented state preserves cardinality and rejects malformed values', function (
    bool $inverse,
    mixed $value,
    bool $valid,
) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(
            source: ['properties' => ['id' => [], 'name' => [], 'active' => []]],
            dest: ['properties' => ['payload' => ['type' => 'array', 'items' => []], 'enabled' => []]],
        )
        ->inverse($inverse);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, $value)], ['mapping' => $field->getValidationRules()])
            ->passes(),
    )
        ->toBe($valid);
})->with('field mapping orientedMappingValues');

it('required destinations come from the schema in both orientations', function (bool $inverse) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(
            source: ['properties' => ['id' => [], 'name' => []]],
            dest: ['required' => ['payload'], 'properties' => ['payload' => ['type' => 'array'], 'optional' => []]],
        )
        ->inverse($inverse);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    $valid = ['payload' => [MappingState::source('id'), MappingState::source('name')]];
    $missing = ['optional' => MappingState::source('id')];

    expect($field->getRequiredTargets())->toBe(['payload']);
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, $valid)], ['mapping' => $field->getValidationRules()])
            ->passes(),
    )->toBeTrue();
    foreach ([null, [], $missing] as $value) {
        expect(
            $validator
                ->make(['mapping' => MappingState::encode($field, $value)], ['mapping' => $field->getValidationRules()])
                ->passes(),
        )->toBeFalse();
    }
})->with([false, true]);

it('schema property names retain their identity and metadata independently of titles', function () {
    $field = FieldMapping::make('mapping')->jsonSchema(source: [
        'properties' => [
            '0' => ['title' => 'Name'],
            '00' => ['title' => 'Name'],
            'customer.name' => [],
        ],
    ], dest: CustomerMappingField::jsonSchema());

    expect(array_column($field->getSources(), 'id'))->toBe(['0', '00', 'customer.name']);
    expect(array_column($field->getSources(), 'label'))->toBe(['Name', 'Name', 'customer.name']);
    expect($field->getTargets()[0])->toMatchArray([
        'id' => 'name',
        'label' => 'Customer name',
        'icon' => 'heroicon-o-user',
        'description' => 'The name displayed on the customer profile.',
        'type' => 'string',
        'required' => true,
    ]);
    expect($field->getMultipleTargets())->toBe(['phone']);
});

it('mapping validation rejects unknown identifiers and malformed values', function (mixed $value, bool $valid) {
    $field = FieldMapping::make('mapping')->jsonSchema(
        source: ['properties' => ['name' => [], 'email' => []]],
        dest: ['properties' => ['title' => [], 'contact' => []]],
    );
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, $value)], ['mapping' => $field->getValidationRules()])
            ->passes(),
    )
        ->toBe($valid);
})->with('field mapping mappingValues');

it('schema callbacks change available array destinations reactively', function () {
    $array = true;
    $field = FieldMapping::make('mapping')->jsonSchema(
        source: ['properties' => ['name' => [], 'email' => []]],
        dest: function () use (&$array) {
            return ['properties' => ['title' => $array ? ['type' => 'array'] : ['type' => 'string']]];
        },
    );
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    $state = ['mapping' => MappingState::encode($field, ['title' => [
        MappingState::source('name'),
        MappingState::source('email'),
    ]])];
    expect($validator->make($state, ['mapping' => $field->getValidationRules()])->passes())->toBeTrue();
    $array = false;
    expect($validator->make($state, ['mapping' => $field->getValidationRules()])->passes())->toBeFalse();
});

it('type compatibility is validated on the server', function (?string $sourceType, ?string $targetType, bool $valid) {
    $source = $sourceType === null ? [] : ['type' => $sourceType];
    $target = $targetType === null ? [] : ['type' => $targetType];
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    foreach ([false, true] as $inverse) {
        foreach ([false, true] as $multiple) {
            $field = FieldMapping::make('mapping')
                ->jsonSchema(
                    source: ['properties' => ['source' => $source]],
                    dest: ['properties' => ['target' => $multiple ? ['type' => 'array', 'items' => $target] : $target]],
                )
                ->inverse($inverse);
            $value = ['target' => $multiple ? [MappingState::source('source')] : MappingState::source('source')];
            expect(
                $validator
                    ->make(['mapping' => MappingState::encode($field, $value)], [
                        'mapping' => $field->getValidationRules(),
                    ])
                    ->passes(),
            )
                ->toBe($valid);
        }
    }
})->with('field mapping typePairs');

it('validates the output schema without changing the source schema', function () {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(
            source: ['properties' => ['active' => ['type' => 'boolean']]],
            dest: ['properties' => ['name' => ['type' => 'string'], 'enabled' => ['type' => 'boolean']]],
        )
        ->outputSchema(fn () => ['properties' => ['active' => ['type' => 'string']]]);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    expect($field->getSources()[0]['type'])->toBe('boolean');
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, ['name' => MappingState::source('active')])], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    )->toBeTrue();
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, ['enabled' => MappingState::source('active')])], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    )->toBeFalse();
});

it('uses configured schema constraints for connection modes and server validation', function (
    array $source,
    array $target,
    SchemaCompatibility $compatibility,
    bool $allowed,
) {
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    foreach ([false, true] as $multiple) {
        $field = FieldMapping::make('mapping')
            ->jsonSchema(
                source: ['properties' => ['source' => ['type' => 'boolean']]],
                dest: ['properties' => ['target' => $multiple ? ['type' => 'array', 'items' => $target] : $target]],
            )
            ->outputSchema(fn () => ['properties' => ['source' => $source]])
            ->compatibility($compatibility);
        $value = ['target' => $multiple ? [MappingState::source('source')] : MappingState::source('source')];

        expect($field->getConnectionModes()['source']['target'] ?? null)
            ->toBe($allowed ? ($multiple ? 'item' : 'whole') : null);
        expect(
            $validator
                ->make(['mapping' => MappingState::encode($field, $value)], [
                    'mapping' => $field->getValidationRules(),
                ])
                ->passes(),
        )
            ->toBe($allowed);
    }
})->with([
    'long string rejected' => [
        ['type' => 'string', 'maxLength' => 255],
        ['type' => 'string', 'maxLength' => 64],
        SchemaCompatibility::make(),
        false,
    ],
    'short string accepted' => [
        ['type' => 'string', 'maxLength' => 64],
        ['type' => 'string', 'maxLength' => 255],
        SchemaCompatibility::make(),
        true,
    ],
    'length check disabled' => [
        ['type' => 'string', 'maxLength' => 255],
        ['type' => 'string', 'maxLength' => 64],
        SchemaCompatibility::make()->stringLengths(false),
        true,
    ],
    'uuid to plain string' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string'],
        SchemaCompatibility::make(),
        true,
    ],
    'uuid to date rejected' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string', 'format' => 'date'],
        SchemaCompatibility::make(),
        false,
    ],
    'format check disabled' => [
        ['type' => 'string', 'format' => 'uuid'],
        ['type' => 'string', 'format' => 'date'],
        SchemaCompatibility::make()->formats(false),
        true,
    ],
]);

it('adds application restrictions to whole and item connections without overriding schema checks', function () {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(source: ['properties' => ['source' => ['type' => 'string', 'x-domain' => 'customers']]], dest: [
            'properties' => [
                'allowed' => ['type' => 'string', 'x-domain' => 'customers'],
                'blocked' => ['type' => 'string', 'x-domain' => 'orders'],
                'wrong_type' => ['type' => 'boolean', 'x-domain' => 'customers'],
                'items' => ['type' => 'array', 'items' => ['type' => 'string', 'x-domain' => 'customers']],
            ],
        ])
        ->canConnectUsing(
            fn (JsonSchema $source, JsonSchema $target) => (
                ($source->toArray()['x-domain'] ?? null) === ($target->toArray()['x-domain'] ?? null)
            ),
        );
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    expect($field->getConnectionModes())->toBe(['source' => ['allowed' => 'whole', 'items' => 'item']]);
    foreach (['allowed' => true, 'blocked' => false, 'wrong_type' => false] as $target => $allowed) {
        expect(
            $validator
                ->make(['mapping' => MappingState::encode($field, [
                    $target => MappingState::source('source'),
                ])], ['mapping' => $field->getValidationRules()])
                ->passes(),
        )
            ->toBe($allowed);
    }
});

it('accepts an array as a whole value or individual items without mixing them', function (bool $inverse) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(
            source: [
                'properties' => [
                    'list' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
            ],
            dest: [
                'required' => ['contacts'],
                'properties' => ['contacts' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
        )
        ->inverse($inverse);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    $values = [
        [['contacts' => MappingState::source('list')], true],
        [['contacts' => [MappingState::source('name'), MappingState::source('email')]], true],
        [['contacts' => [MappingState::source('list'), MappingState::source('name')]], false],
        [['contacts' => [MappingState::source('list')]], false],
    ];
    foreach ($values as [$value, $valid]) {
        expect(
            $validator
                ->make(['mapping' => MappingState::encode($field, $value)], ['mapping' => $field->getValidationRules()])
                ->passes(),
        )
            ->toBe($valid);
    }
})->with([false, true]);

it('accepts array sources as items only when the destination items are arrays', function () {
    $field = FieldMapping::make('mapping')->jsonSchema(
        source: ['properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'string']]]],
        dest: [
            'properties' => [
                'matrix' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
        ],
    );
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, ['matrix' => [MappingState::source('list')]])], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    )->toBeTrue();
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, ['matrix' => MappingState::source('list')])], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    )->toBeFalse();
});

it('static values are accepted for every known destination and retain string form validation', function (
    mixed $value,
    bool $valid,
) {
    $field = FieldMapping::make('mapping')
        ->jsonSchema(dest: ['required' => ['enabled'], 'properties' => ['enabled' => ['type' => 'boolean']]]);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));
    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, $value)], ['mapping' => $field->getValidationRules()])
            ->passes(),
    )
        ->toBe($valid);
})->with([
    [['enabled' => MappingState::value('true')], true],
    [['enabled' => MappingState::value('')], false],
    [['enabled' => MappingState::value(true)], false],
    [['enabled' => []], false],
    [['enabled' => [...MappingState::value('true'), 'type' => 'boolean']], false],
    [['other' => MappingState::value('true')], false],
]);

it('rejects source settings for identifiers outside the source schema', function () {
    $field = FieldMapping::make('mapping')->jsonSchema(source: ['properties' => ['name' => []]]);
    $validator = new Factory(new Translator(new ArrayLoader, 'en'));

    expect(
        $validator
            ->make(['mapping' => MappingState::encode($field, [], ['missing' => ['alias' => 'Unknown']])], [
                'mapping' => $field->getValidationRules(),
            ])
            ->passes(),
    )->toBeFalse();
});
