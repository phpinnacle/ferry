<?php

use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use Tests\TestCase;

require_once __DIR__ . '/../Fakes/Transform.php';

uses(TestCase::class);

it('calculates a composed output schema from input schema and mapping options', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        new FakeTransform(
            'pack',
            accepts: fn (JsonSchema $input) => $input->singleType() === 'string',
            output: fn () => JsonSchema::fromArray(['type' => 'array', 'items' => ['type' => 'string']]),
        ),
        new FakeTransform(
            'join',
            accepts: fn (JsonSchema $input) => $input->compatible(JsonSchema::fromArray([
                'type' => 'array',
                'items' => ['type' => 'string'],
            ])),
            output: fn () => JsonSchema::fromArray(['type' => ['string', 'null'], 'maxLength' => 100]),
        ),
    );
    $schema = JsonSchema::fromArray([
        'properties' => [
            '_code' => ['type' => 'string', 'maxLength' => 11],
            'id' => ['type' => 'integer'],
        ],
    ]);
    $transforms = [['type' => 'pack', 'data' => []], ['type' => 'join', 'data' => []]];
    $mapping = FieldMapping::fromArray([
        'kind' => 'source',
        'source' => '_code',
        'target' => 'title',
        'options' => ['transforms' => $transforms],
    ]);

    $key = new FieldMapping(source: 'id', target: 'id');
    app(ReplicaSchema::class)->assertValid([$mapping, $key], null, ['id'], $schema);

    expect($registry->mappingSchema($mapping, $schema)->toArray())
        ->toBe(['type' => ['string', 'null'], 'maxLength' => 100])
        ->and($mapping->transforms())
        ->toEqual([new TransformSetting('pack'), new TransformSetting('join')])
        ->and(FieldMapping::fromArray($mapping->jsonSerialize()))
        ->toEqual($mapping);

    $packed = new FieldMapping('_code', 'title', ['transforms' => [$transforms[0]]]);
    expect(fn () => app(ReplicaSchema::class)->assertValid(
        [$packed, $key],
        null,
        ['id'],
        $schema,
    ))
        ->toThrow(ValidationException::class);
});

it('round-trips source and static mappings without synthetic transforms', function () {
    $plain = new FieldMapping('_description', 'title', ['display' => ['mode' => 'compact']]);
    $constant = new FieldMapping(source: null, target: 'is_active', static: true, kind: FieldKind::Static);

    expect(array_keys($plain->jsonSerialize()))
        ->toEqualCanonicalizing(['kind', 'source', 'target', 'options'])
        ->and(FieldMapping::fromArray($plain->jsonSerialize()))
        ->toEqual($plain)
        ->and($plain->jsonSerialize()['options'])
        ->toBe(['display' => ['mode' => 'compact']])
        ->and(FieldMapping::fromArray(json_decode(json_encode($constant), true)))
        ->toEqual($constant);
});
