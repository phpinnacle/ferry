<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;
use Tests\TestCase;

uses(TestCase::class);

it('preserves a composed output schema in ready mapping rows', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        Transformation::make('pack')
            ->inputSchema(['type' => 'string'])
            ->outputSchema(['type' => 'array', 'items' => ['type' => 'string']]),
        Transformation::make('join')
            ->inputSchema(['type' => 'array', 'items' => ['type' => 'string']])
            ->outputSchema(['type' => ['string', 'null'], 'maxLength' => 100]),
    );
    $field = JsonSchema::fromArray(['type' => 'string', 'maxLength' => 11]);
    $transforms = [['type' => 'pack', 'data' => []], ['type' => 'join', 'data' => []]];
    $mapping = FieldMapping::fromArray([
        'kind' => 'source',
        'source' => '_code',
        'target' => 'title',
        'field' => $field->toArray(),
        'result' => $registry->resultSchema($field, $transforms)->toArray(),
        'transforms' => $transforms,
    ]);

    $key = new FieldMapping('id', 'id', JsonSchema::fromArray(['type' => 'integer']));
    ReplicaSchema::assertValid([$mapping, $key], null, ['id']);

    expect($mapping->outputSchema()->toArray())
        ->toBe(['type' => ['string', 'null'], 'maxLength' => 100])
        ->and($mapping->transforms)
        ->toEqual([new TransformSetting('pack'), new TransformSetting('join')])
        ->and(FieldMapping::fromArray($mapping->jsonSerialize()))
        ->toEqual($mapping);

    $packed = new FieldMapping('_code', 'title', $field, $registry->resultSchema($field, [$transforms[0]]));
    expect(fn () => ReplicaSchema::assertValid([$packed, $key], null, ['id']))->toThrow(ValidationException::class);
});

it('round-trips source and static mappings without synthetic transforms', function () {
    $plain = new FieldMapping('_description', 'title', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]));
    $constant = new FieldMapping(null, 'is_active', null, static: true, kind: FieldKind::Static);

    expect(array_keys($plain->jsonSerialize()))
        ->toEqualCanonicalizing(['kind', 'source', 'target', 'field', 'result', 'transforms'])
        ->and(FieldMapping::fromArray($plain->jsonSerialize()))
        ->toEqual($plain)
        ->and(FieldMapping::fromArray(json_decode(json_encode($constant), true)))
        ->toEqual($constant);
});

it('preserves native static values in ready mapping rows', function (string|int|float|bool $value) {
    $mapping = FieldMapping::fromArray(['kind' => 'static', 'target' => 'status', 'static' => $value]);

    ReplicaSchema::assertValid([$mapping, new FieldMapping('id', 'id', JsonSchema::fromArray([
            'type' => 'integer',
        ]))], null, ['id']);

    expect($mapping->static)
        ->toBe($value)
        ->and($mapping->transforms)
        ->toBe([])
        ->and($mapping->jsonSerialize())
        ->toBe(['kind' => 'static', 'target' => 'status', 'static' => $value]);
})->with([
    'false' => [false],
    'zero' => [0],
    'decimal' => [1.0],
    'string' => ['true'],
]);
