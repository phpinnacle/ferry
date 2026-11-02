<?php

use PHPinnacle\Eidos\Forms\SchemaCompatibility;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\EmptyStringToNull;
use PHPinnacle\Jason\Schema;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('offers empty string conversion for text but excludes non-string and logical temporal inputs', function (
    array $input,
    bool $accepted,
) {
    expect(EmptyStringToNull::make()->accepts(Schema::fromArray($input)))->toBe($accepted);
})->with([
    'string' => [['type' => 'string'], true],
    'nullable string' => [['type' => ['string', 'null']], true],
    'boolean' => [['type' => 'boolean'], false],
    'number' => [['type' => 'number'], false],
    'date' => [['type' => 'string', 'format' => 'date'], false],
    'time' => [['type' => 'string', 'format' => 'time'], false],
    'timestamp' => [['type' => 'string', 'format' => 'date-time'], false],
]);

it('makes output nullable without carrying source defaults or constraints into destination matching', function () {
    $input = Schema::fromArray([
        'type' => 'string',
        'enum' => ['', ' A '],
        'default' => '',
        'minLength' => 0,
        'maxLength' => 3,
    ]);
    $output = EmptyStringToNull::make()->output($input, ['trim' => true]);

    expect($output->toArray())->toBe(['type' => ['string', 'null']]);
    expect(SchemaCompatibility::make()->accepts($output, Schema::fromArray(['type' => 'string'])))->toBeFalse();
    expect(SchemaCompatibility::make()->accepts($output, Schema::fromArray(['type' => [
        'string',
        'null',
    ]])))->toBeTrue();
});

it('compiles empty string conversion before renames with the selected target and trim setting', function (
    TransformTarget $target,
    array $keyMap,
    array $params,
    string $trim,
    string $chain,
) {
    $object = StructureItems::make(['fields' => ['name' => Schema::fromArray(['type' => 'string'])]]);
    $mapping = new FieldMapping('name', 'result', [
        'transforms' => [
            ['type' => 'empty_string_to_null', 'data' => $params],
        ],
    ]);
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(TransformRegistry::class)->get('empty_string_to_null'))->toBeInstanceOf(EmptyStringToNull::class);
    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())
        ->toBe(['type' => ['string', 'null']]);
    expect(app(TransformFactory::class)->pipeline($replica, $object, $keyMap))->toMatchArray([
        'transforms' => $chain,
        'transforms.field_0_0.type' => 'org.phpinnacle.toblerone.EmptyStringToNullTransform$' . ucfirst($target->value),
        'transforms.field_0_0.fields' => 'name',
        'transforms.field_0_0.trim' => $trim,
    ]);
})->with([
    'default' => [TransformTarget::Value, [], [], 'false', 'field_0_0,rename_values'],
    'explicit false' => [TransformTarget::Value, [], ['trim' => false], 'false', 'field_0_0,rename_values'],
    'trimmed value' => [TransformTarget::Value, [], ['trim' => true], 'true', 'field_0_0,rename_values'],
    'trimmed key' => [
        TransformTarget::Key,
        ['name' => 'result'],
        ['trim' => true],
        'true',
        'field_0_0,rename_keys,rename_values',
    ],
]);

it('inserts empty constants before applying empty string conversion', function () {
    $object = StructureItems::make();
    $mapping = new FieldMapping(
        null,
        'note',
        ['transforms' => [['type' => 'empty_string_to_null']]],
        '',
        FieldKind::Static,
    );
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'field_0_0,field_0_1,rename_values',
        'transforms.field_0_0.static.value' => '',
        'transforms.field_0_1.type' => 'org.phpinnacle.toblerone.EmptyStringToNullTransform$Value',
        'transforms.field_0_1.fields' => '__ferry_static_0',
        'transforms.field_0_1.trim' => 'false',
    ]);
});
