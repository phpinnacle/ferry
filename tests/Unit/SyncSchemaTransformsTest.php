<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    FerryPlugin::make();
    $this->object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_code' => new StringField(length: 11, fixed: true),
            '_description' => new StringField(length: 100, fixed: false),
        ],
        'properties' => [],
    ]);
});

it('persists the final output schema and derives its supported column type', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        Transformation::make('pack')
            ->inputSchema(['type' => 'string'])
            ->outputSchema(['type' => 'array', 'items' => ['type' => 'string']]),
        Transformation::make('join')
            ->inputSchema(['type' => 'array', 'items' => ['type' => 'string']])
            ->outputSchema(['type' => ['string', 'null'], 'maxLength' => 100]),
    );
    $settings = [new TransformSetting('pack'), new TransformSetting('join')];

    $mappings = SyncSchema::fromBindings($this->object, ['_code' => 'title'], $registry, transforms: ['fields' => [
        '_code' => $settings,
    ]]);

    expect($mappings[0]->result?->toArray())
        ->toBe(['type' => ['string', 'null'], 'maxLength' => 100])
        ->and($mappings[0]->type())
        ->toBe(ColumnType::String)
        ->and($mappings[0]->transforms)
        ->toEqual($settings)
        ->and(FieldMapping::fromArray($mappings[0]->jsonSerialize()))
        ->toEqual($mappings[0]);
    expect(fn () => SyncSchema::fromBindings($this->object, ['_code' => 'title'], $registry, transforms: [
        'fields' => ['_code' => [new TransformSetting('pack')]],
    ]))
        ->toThrow(ValidationException::class);

    foreach ([
        [[new TransformSetting('cast', ['type' => 'integer'])], ColumnType::Integer],
        [[new TransformSetting('pack')], null],
    ] as [$transforms, $result]) {
        $unbound = SyncSchema::fromBindings($this->object, [], $registry, transforms: [
            'fields' => ['_code' => $transforms],
        ])[0];

        expect($unbound->target)->toBeNull()->and($unbound->type())->toBe($result);
    }
});

it('validates settings without configured or resolvable handlers', function () {
    config(['phpinnacle-ferry.transforms' => []]);
    app()->bind(
        TransformFactory::class,
        fn () => throw new LogicException('Input validation must not resolve handlers.'),
    );

    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_code' => 'active'],
        app(TransformRegistry::class),
        transforms: ['fields' => ['_code' => [new TransformSetting('cast', ['type' => 'boolean'])]]],
    );
    expect($mappings[0]->type())
        ->toBe(ColumnType::Boolean);
});

it('lets a field transform change the type that is checked against the destination', function () {
    $cast = new TransformSetting('cast', ['type' => 'boolean']);

    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name', '_code' => 'is_active'],
        app(TransformRegistry::class),
        customers_destination(),
        ['fields' => ['_code' => [$cast]], 'constants' => ['tax_number' => 'none']],
    );

    expect($mappings[1]->type())
        ->toBe(ColumnType::Boolean)
        ->and($mappings[1]->sourceType())
        ->toBe(ColumnType::String)
        ->and($mappings[1]->transforms)
        ->toEqual([$cast]);
});

it('validates the final cast type and preserves every step in the connector pipeline', function () {
    $settings = [
        new TransformSetting('cast', ['type' => 'integer']),
        new TransformSetting('cast', ['type' => 'boolean']),
    ];
    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name', '_code' => 'is_active'],
        app(TransformRegistry::class),
        customers_destination(),
        ['fields' => ['_code' => $settings], 'constants' => ['tax_number' => 'none']],
    );
    $sync = new Sync(['schema' => $mappings]);
    $pipeline = app(TransformFactory::class)->pipeline($sync, $this->object);

    expect($mappings[1]->type())
        ->toBe(ColumnType::Boolean)
        ->and($mappings[1]->transforms)
        ->toEqual($settings)
        ->and([$pipeline['transforms.field_1_0.spec'], $pipeline['transforms.field_1_1.spec']])
        ->toBe(['_code:int64', '_code:boolean']);
});

it('maps a static value to a destination field without a source field', function () {
    $mappings = SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'name'],
        app(TransformRegistry::class),
        customers_destination(),
        transforms: [
            'constants' => [
                'tax_number' => 'none',
                'is_active' => true,
            ],
        ],
    );

    expect($mappings)
        ->toHaveCount(5)
        ->and($mappings[1]->source)
        ->toBeNull()
        ->and($mappings[1]->field)
        ->toBeNull()
        ->and($mappings[1]->target)
        ->toBe('tax_number')
        ->and($mappings[2]->type())
        ->toBe(ColumnType::Boolean)
        ->and($mappings[2]->transforms)
        ->toBe([])
        ->and($mappings[2]->static)
        ->toEqual(true);
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
        app(TransformRegistry::class),
        customers_destination(),
        ['fields' => $fieldTransforms, 'constants' => $constants],
    ))
        ->toThrow(ValidationException::class, __('phpinnacle-ferry::resources.sync.errors.' . $error, $replace));
})->with([
    'constant that does not match its target' => [
        [],
        ['is_active' => 'maybe'],
        'incompatible_destination_field',
        ['field' => 'Is Active'],
    ],
    'constant incompatible with the destination' => [
        [],
        ['is_active' => 1],
        'incompatible_destination_field',
        ['field' => 'Is Active'],
    ],
    'constant over a mapped column' => [
        [],
        ['name' => 'x'],
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

it('casts a numeric source to the selected supported type', function (int $scale, ColumnType $type) {
    $object = new ConnectionMetadata([
        'system' => [
            '_idrref' => new ScalarField(FieldType::Id),
            '_qty' => new NumberField(precision: 10, scale: $scale, unsigned: false),
        ],
        'properties' => [],
    ]);
    $mappings = SyncSchema::fromBindings(
        $object,
        ['_qty' => 'qty'],
        app(TransformRegistry::class),
        transforms: ['fields' => ['_qty' => [new TransformSetting('cast', ['type' => $type->value])]]],
    );

    expect($mappings[0]->type())->toBe($type);
})->with([0, 2])->with(ColumnType::castable());

it('refuses transforms that are not registered', function () {
    expect(fn () => SyncSchema::fromBindings(
        $this->object,
        ['_description' => 'title'],
        app(TransformRegistry::class),
        transforms: ['fields' => ['_description' => [new TransformSetting('missing')]]],
    ))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.sync.errors.transform_missing', [
            'transform' => 'missing',
        ]));
});

it('round-trips source and static mappings without synthetic transforms', function () {
    $plain = new FieldMapping('_description', 'title', new StringField(length: 100, fixed: false));
    $constant = new FieldMapping(null, 'is_active', null, static: true, kind: FieldKind::Static);

    expect(array_keys($plain->jsonSerialize()))
        ->toEqualCanonicalizing(['kind', 'source', 'target', 'field', 'result', 'transforms'])
        ->and(FieldMapping::fromArray($plain->jsonSerialize()))
        ->toEqual($plain)
        ->and(FieldMapping::fromArray(json_decode(json_encode($constant), true)))
        ->toEqual($constant);
});

it('preserves false and zero static values without transform settings', function (string|int|float|bool $value) {
    $mapping = SyncSchema::fromBindings(
        $this->object,
        [],
        app(TransformRegistry::class),
        transforms: ['constants' => ['status' => $value]],
    )[0];

    expect($mapping->static)
        ->toEqual($value)
        ->and($mapping->transforms)
        ->toBe([])
        ->and($mapping->jsonSerialize())
        ->not->toHaveKey('transforms');
})->with([
    'false' => [false],
    'zero' => [0],
    'decimal' => [1.0],
    'string' => ['true'],
]);

it('rejects non-scalar static input at the schema boundary', function (mixed $value) {
    expect(fn () => SyncSchema::fromBindings($this->object, [], app(TransformRegistry::class), transforms: [
        'constants' => ['status' => $value],
    ]))->toThrow(ValidationException::class);
})->with([[null], [[]], [['nested' => 'value']]]);
