<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    $this->registry = $this->app->make(TransformFactory::class);
    $this->object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
    ]);
});

it('lets a field transform change the type that is checked against the destination', function () {
    $cast = new TransformSetting('cast', ['type' => 'boolean']);

    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name', '_code' => 'is_active'],
        $this->registry,
        customers_destination(),
        ['fields' => ['_code' => [$cast]], 'constants' => ['tax_number' => ['type' => 'string', 'value' => 'none']]],
    );

    expect($mappings[1]->result)
        ->toBe(ColumnType::Boolean)
        ->and($mappings[1]->sourceType())
        ->toBe(ColumnType::String)
        ->and($mappings[1]->transforms)
        ->toBe([$cast]);
});

it('maps a static value to a destination field without a source field', function () {
    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name'],
        $this->registry,
        customers_destination(),
        transforms: [
            'constants' => [
                'tax_number' => ['type' => 'string', 'value' => 'none'],
                'is_active' => ['type' => 'boolean', 'value' => 'true'],
            ],
        ],
    );

    expect($mappings)
        ->toHaveCount(3)
        ->and($mappings[1]->source)
        ->toBeNull()
        ->and($mappings[1]->field)
        ->toBeNull()
        ->and($mappings[1]->column)
        ->toBe('tax_number')
        ->and($mappings[2]->type())
        ->toBe(ColumnType::Boolean)
        ->and($mappings[2]->transforms)
        ->toEqual([new TransformSetting('static_value', [
            'type' => 'boolean',
            'value' => 'true',
        ])]);
});

it('rejects transform settings that cannot be applied', function (
    array $fieldTransforms,
    array $constants,
    string $error,
    array $replace,
) {
    expect(fn () => SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name', '_code' => 'tax_number'],
        $this->registry,
        customers_destination(),
        ['fields' => $fieldTransforms, 'constants' => $constants],
    ))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.' . $error, $replace));
})->with([
    'unsupported cast type' => [
        ['_code' => [new TransformSetting('cast', ['type' => 'reference'])]],
        [],
        'transform_invalid_params',
        fn () => [
            'transform' => __('phpinnacle-ferry::resources.transform.cast'),
            'error' => 'The selected type is invalid.',
        ],
    ],
    'constant that does not match its type' => [
        [],
        ['is_active' => ['type' => 'boolean', 'value' => 'maybe']],
        'transform_invalid_params',
        fn () => [
            'transform' => __('phpinnacle-ferry::resources.transform.static_value'),
            'error' => 'The selected value is invalid.',
        ],
    ],
    'constant incompatible with the destination' => [
        [],
        ['is_active' => ['type' => 'integer', 'value' => '1']],
        'incompatible_destination_field',
        ['field' => 'Is Active'],
    ],
    'constant over a mapped column' => [
        [],
        ['name' => ['type' => 'string', 'value' => 'x']],
        'constant_column_taken',
        ['column' => 'name'],
    ],
    'cast result incompatible with the destination' => [
        ['_code' => [new TransformSetting('cast', ['type' => 'integer'])]],
        [],
        'incompatible_destination_field',
        ['field' => 'Tax Number'],
    ],
]);

it('casts a 1C number only to a string, the one cast Kafka Connect applies to decimals', function () {
    $object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_qty' => new NumberField(precision: 10, scale: 0, unsigned: false),
        ],
        'properties' => [],
    ]);
    $cast = fn (string $type) => SyncSchema::fromBindings(
        $object,
        ['_qty' => 'qty'],
        $this->registry,
        transforms: ['fields' => ['_qty' => [new TransformSetting('cast', ['type' => $type])]]],
    );

    expect($cast('string')[0]->result)->toBe(ColumnType::String);

    try {
        $cast('boolean');
        test()->fail('A 1C number cannot be cast to a boolean.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'data.transforms' => [
                __('phpinnacle-ferry::resources.sync.errors.transform_field_unsupported', [
                    'transform' => __('phpinnacle-ferry::resources.transform.cast'),
                    'field' => '_qty',
                ]),
            ],
        ]);
    }
});

it('refuses transforms that are not registered', function () {
    expect(fn () => SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'title'],
        $this->registry,
        transforms: ['fields' => ['_description' => [new TransformSetting('missing')]]],
    ))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.sync.errors.transform_missing', [
            'transform' => 'missing',
        ]));
});

it('accepts only record transforms for a whole synchronization', function () {
    expect(fn () => SyncSchema::fromTransforms(
        [new TransformSetting('cast', ['type' => 'string'])],
        $this->object,
        $this->registry,
    ))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.transform_scope_mismatch', [
            'transform' => __('phpinnacle-ferry::resources.transform.cast'),
        ]))
        ->and(SyncSchema::fromTransforms([], $this->object, $this->registry))
        ->toBe([]);
});

it('requires the columns a record transform reads to exist in the object', function () {
    $exists = new TransformSetting('filter', [
        'mode' => 'exclude',
        'column' => '_code',
        'type' => 'string',
        'value' => 'x',
    ]);
    $gone = new TransformSetting('filter', [
        'mode' => 'exclude',
        'column' => '_gone',
        'type' => 'string',
        'value' => 'x',
    ]);

    expect(SyncSchema::fromTransforms([$exists], $this->object, $this->registry))
        ->toEqual([$exists])
        ->and(fn () => SyncSchema::fromTransforms([$exists, $gone], $this->object, $this->registry))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.transform_column_missing', [
            'column' => '_gone',
        ]));
});

it('takes the type of a filtered column from the object instead of the submitted settings', function () {
    $object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(length: 11, fixed: true),
            '_folder' => new ScalarField(FieldType::Boolean),
        ],
        'properties' => [],
    ]);
    $forged = new TransformSetting('filter', [
        'mode' => 'exclude',
        'column' => '_code',
        'type' => 'boolean',
        'value' => 'x',
    ]);
    $omitted = new TransformSetting('filter', ['mode' => 'exclude', 'column' => '_folder', 'value' => 'true']);
    $disguised = new TransformSetting('filter', [
        'mode' => 'include',
        'column' => '_folder',
        'type' => 'string',
        'value' => 'maybe',
    ]);

    expect(SyncSchema::fromTransforms([$forged, $omitted], $object, $this->registry))
        ->toEqual([
            new TransformSetting('filter', [
                'mode' => 'exclude',
                'column' => '_code',
                'type' => 'string',
                'value' => 'x',
            ]),
            new TransformSetting('filter', [
                'mode' => 'exclude',
                'column' => '_folder',
                'type' => 'boolean',
                'value' => 'true',
            ]),
        ])
        ->and(fn () => SyncSchema::fromTransforms([$disguised], $object, $this->registry))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.transform_invalid_params', [
            'transform' => __('phpinnacle-ferry::resources.transform.filter'),
            'error' => 'The selected value is invalid.',
        ]));
});

it('keeps the stored shape of a mapping without transforms and restores one with them', function () {
    $plain = new FieldMapping('_description', 'title', new StringField(length: 100, fixed: false));
    $constant = new FieldMapping(null, 'is_active', null, ColumnType::Boolean, [
        new TransformSetting('static_value', ['type' => 'boolean', 'value' => 'true']),
    ]);

    expect(array_keys($plain->jsonSerialize()))
        ->toBe(['source', 'column', 'field'])
        ->and(FieldMapping::fromArray($plain->jsonSerialize()))
        ->toEqual($plain)
        ->and(FieldMapping::fromArray(json_decode(json_encode($constant), true)))
        ->toEqual($constant);
});
