<?php

use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\BooleanToString;
use PHPinnacle\Ferry\Transformations\StringMap;
use PHPinnacle\Ferry\Transformations\Toggle;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('offers Toblerone transforms only for their supported input types', function (array $input, array $accepted) {
    foreach ([BooleanToString::make(), StringMap::make(), Toggle::make()] as $transform) {
        expect($transform->accepts(JsonSchema::fromArray($input)))
            ->toBe(in_array($transform->getKey(), $accepted, true));
    }
})->with([
    'boolean' => [['type' => 'boolean'], ['boolean_to_string', 'toggle']],
    'nullable boolean' => [['type' => ['boolean', 'null']], ['boolean_to_string', 'toggle']],
    'text' => [['type' => 'string'], ['string_map']],
    'nullable text' => [['type' => ['string', 'null']], ['string_map']],
    'date' => [['type' => 'string', 'format' => 'date'], []],
    'reference' => [['type' => 'string', 'x-ferry-reference' => true], []],
    'integer' => [['type' => 'integer'], []],
    'union' => [['type' => ['boolean', 'string']], []],
]);

it('maps boolean schemas to exact string enums including empty strings and null', function () {
    $transform = BooleanToString::make();
    expect($transform->output(JsonSchema::fromArray(['type' => ['boolean', 'null']]), [
        'true' => '',
        'false' => 'no',
    ])?->toArray())
        ->toBe(['type' => ['string', 'null'], 'enum' => ['', 'no', null], 'minLength' => 0, 'maxLength' => 2]);
    expect($transform->output(JsonSchema::fromArray(['type' => 'boolean']), [
        'true' => 'same',
        'false' => 'same',
    ])?->toArray())
        ->toBe(['type' => 'string', 'enum' => ['same'], 'minLength' => 4, 'maxLength' => 4]);
    expect($transform->output(JsonSchema::fromArray(['type' => 'boolean', 'enum' => [false]]), [
        'true' => 'yes',
        'false' => 'no',
    ])?->toArray())
        ->toBe(['type' => 'string', 'enum' => ['no'], 'minLength' => 2, 'maxLength' => 2]);
    expect($transform->output(JsonSchema::fromArray(['type' => 'boolean']), []))->toBeNull();
});

it('inverts effective boolean enums and preserves nullability', function () {
    $input = JsonSchema::fromArray([
        'type' => ['boolean', 'null'],
        'allOf' => [
            ['enum' => [true, false, null]],
            ['enum' => [false, null]],
        ],
    ]);
    expect(Toggle::make()->output($input, [])->toArray())->toBe([
        'type' => ['boolean', 'null'],
        'enum' => [true, null],
    ]);
    expect(
        Toggle::make()
            ->output(JsonSchema::fromArray(['type' => 'boolean']), [])
            ->toArray(),
    )->toBe(['type' => 'boolean']);
});

it('projects exact one-pass string replacements and discards obsolete string constraints', function () {
    $input = JsonSchema::fromArray([
        'type' => ['string', 'null'],
        'format' => 'email',
        'maxLength' => 4,
        'pattern' => '^draft$',
        'enum' => ['draft', 'published', 'unknown', 'Draft', '', null],
    ]);
    $params = ['mapping' => ['draft' => 'published', 'published' => '', '' => 'empty']];
    expect(StringMap::make()->output($input, $params)?->toArray())->toBe([
        'type' => ['string', 'null'],
        'enum' => ['published', '', 'unknown', 'Draft', 'empty', null],
        'minLength' => 0,
        'maxLength' => 9,
    ]);
    expect(
        StringMap::make()
            ->output(JsonSchema::fromArray(['type' => 'string', 'maxLength' => 4]), $params)
            ?->toArray(),
    )
        ->toBe(['type' => 'string', 'maxLength' => 9]);
    expect(StringMap::make()->output($input, []))->toBeNull();
});

it('preserves empty and whitespace boolean labels through native forms', function () {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(BooleanToString::make()));
    $schema->fill(['true' => '', 'false' => '   ']);
    expect($schema->getState())->toBe(['true' => '', 'false' => '   ']);
    $schema->fill(['true' => [], 'false' => 'no']);
    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('edits the replacement dictionary using native KeyValue while preserving exact text', function () {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(StringMap::make()));
    expect($schema->getComponent('mapping'))->toBeInstanceOf(KeyValue::class);
    $mapping = ['draft' => '', ' padded ' => '  ', 'a,b;c.d' => 'punctuation', '1' => 'one'];
    $schema->fill(['mapping' => $mapping]);
    expect($schema->getState())->toBe(['mapping' => $mapping]);
});

it('validates replacement dictionary values at the native form boundary', function (array $mapping) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(StringMap::make()));
    $schema->fill(['mapping' => $mapping]);
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    'empty dictionary' => [[]],
    'numeric replacement' => [['draft' => 1]],
    'boolean replacement' => [['draft' => true]],
    'null replacement' => [['draft' => null]],
]);

it('compiles the new Toblerone chain before renames without changing the key', function () {
    $object = StructureItems::make(['fields' => ['active' => JsonSchema::fromArray(['type' => ['boolean', 'null']])]]);
    $mapping = new FieldMapping('active', 'status', [
        'transforms' => [
            ['type' => 'toggle'],
            ['type' => 'boolean_to_string', 'data' => ['true' => 'yes', 'false' => 'no']],
            ['type' => 'string_map', 'data' => ['mapping' => ['yes' => 'active', 'no' => '']]],
        ],
    ]);
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);
    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())->toBe([
        'type' => ['string', 'null'],
        'enum' => ['active', '', null],
        'minLength' => 0,
        'maxLength' => 6,
    ]);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'field_0_0,field_0_1,field_0_2,rename_values',
        'transforms.field_0_0.type' => 'org.phpinnacle.toblerone.ToggleTransform$Value',
        'transforms.field_0_0.fields' => 'active',
        'transforms.field_0_1.type' => 'org.phpinnacle.toblerone.BooleanToStringTransform$Value',
        'transforms.field_0_1.true' => 'yes',
        'transforms.field_0_1.false' => 'no',
        'transforms.field_0_2.type' => 'org.phpinnacle.toblerone.StringMapTransform$Value',
        'transforms.field_0_2.field' => 'active',
        'transforms.field_0_2.mapping.yes' => 'active',
        'transforms.field_0_2.mapping.no' => '',
        'transforms.rename_values.renames' => 'active:status',
    ]);
});

it('applies the Toblerone chain to constants before renames after inserting their typed value', function () {
    $mapping = new FieldMapping(
        null,
        'status',
        [
            'transforms' => [
                ['type' => 'toggle'],
                ['type' => 'boolean_to_string', 'data' => ['true' => 'yes', 'false' => 'no']],
                ['type' => 'string_map', 'data' => ['mapping' => ['yes' => ' ready ', 'no' => '']]],
            ],
        ],
        static: true,
        kind: FieldKind::Static,
    );
    $object = StructureItems::make();
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())->toBe([
        'type' => 'string',
        'enum' => [''],
        'minLength' => 0,
        'maxLength' => 0,
    ]);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'field_0_0,field_0_0_cast,field_0_1,field_0_2,field_0_3,rename_values',
        'transforms.field_0_0.static.field' => '__ferry_static_0',
        'transforms.field_0_0.static.value' => 'true',
        'transforms.field_0_0_cast.spec' => '__ferry_static_0:boolean',
        'transforms.field_0_1.fields' => '__ferry_static_0',
        'transforms.field_0_2.fields' => '__ferry_static_0',
        'transforms.field_0_3.field' => '__ferry_static_0',
        'transforms.field_0_3.mapping.no' => '',
    ]);
});
