<?php

use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\BooleanToString;
use PHPinnacle\Ferry\Transformations\Json;
use PHPinnacle\Ferry\Transformations\Money;
use PHPinnacle\Ferry\Transformations\Radix;
use PHPinnacle\Ferry\Transformations\StringMap;
use PHPinnacle\Ferry\Transformations\Toggle;
use PHPinnacle\Jason\Schema;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';
require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('offers JSON, money and radix transforms for their supported schemas', function (array $input, array $accepted) {
    foreach ([Json::make(), Money::make(), Radix::make()] as $transform) {
        expect($transform->accepts(Schema::fromArray($input)))
            ->toBe(in_array($transform->getKey(), $accepted, true));
    }
})->with([
    'decimal' => [['type' => ['number', 'null']], ['json', 'money']],
    'integer' => [['type' => 'integer'], ['json', 'money', 'radix']],
    'text' => [['type' => ['string', 'null']], ['json', 'radix']],
    'boolean' => [['type' => 'boolean'], ['json']],
    'date' => [['type' => 'string', 'format' => 'date-time'], ['json']],
    'UUID' => [['type' => 'string', 'format' => 'uuid'], ['json', 'radix']],
    'object' => [['type' => 'object'], ['json']],
    'array' => [['type' => 'array', 'items' => ['type' => 'integer']], ['json']],
    'union' => [['type' => ['integer', 'string']], ['json']],
]);

it('replaces numeric and JSON constraints while preserving nullability', function () {
    $amount = Schema::fromArray(['type' => ['number', 'null'], 'minimum' => 0, 'multipleOf' => 0.01]);
    expect(Money::make()->output($amount, ['currency' => 'RUB'])?->toArray())
        ->toBe(['type' => ['integer', 'null']]);
    expect(Money::make()->output($amount, []))->toBeNull();
    expect(
        Radix::make()
            ->output(Schema::fromArray(['type' => 'integer', 'maximum' => 255]), ['radix' => '16'])
            ?->toArray(),
    )
        ->toBe(['type' => 'string']);
    expect(
        Radix::make()
            ->output(Schema::fromArray(['type' => ['string', 'null'], 'maxLength' => 4]), ['radix' => '16'])
            ?->toArray(),
    )
        ->toBe(['type' => ['integer', 'null']]);
    expect(Radix::make()->output(Schema::fromArray(['type' => 'integer']), []))->toBeNull();
    expect(
        Json::make()
            ->output(Schema::fromArray(['type' => 'string', 'enum' => ['hello'], 'maxLength' => 5]), [])
            ->toArray(),
    )
        ->toBe(['type' => 'string']);
    expect(
        Json::make()
            ->output(Schema::fromArray(['type' => ['object', 'null']]), [])
            ->toArray(),
    )
        ->toBe(['type' => ['string', 'null']]);
    expect(Json::make()->output(Schema::fromArray([]), [])->toArray())
        ->toBe(['type' => ['string', 'null']]);
});

it('validates money and radix settings at their form boundary', function (string $type, array $params) {
    $transform = $type === 'money' ? Money::make() : Radix::make();
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema($transform));
    $schema->fill($params);

    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())
                ->toHaveKey('data.' . ($type === 'money' ? 'currency' : 'radix')),
        );
    $valid = $type === 'money' ? ['currency' => 'KWD'] : ['radix' => 16];
    $schema->fill($valid);
    expect($schema->getState())->toBe($valid);
})->with([
    'missing currency' => ['money', []],
    'lowercase currency' => ['money', ['currency' => 'rub']],
    'long currency' => ['money', ['currency' => 'RUBB']],
    'non-string currency' => ['money', ['currency' => ['RUB']]],
    'missing base' => ['radix', ['radix' => null]],
    'small base' => ['radix', ['radix' => '1']],
    'large base' => ['radix', ['radix' => '37']],
    'fractional base' => ['radix', ['radix' => '2.5']],
]);

it('compiles money, radix and JSON before renames for the selected target', function (
    TransformTarget $target,
    array $keyMap,
    string $chain,
) {
    $object = StructureItems::make(['fields' => ['amount' => Schema::fromArray(['type' => ['number', 'null']])]]);
    $mapping = new FieldMapping('amount', 'result', [
        'transforms' => [
            ['type' => 'money', 'data' => ['currency' => 'KWD']],
            ['type' => 'radix', 'data' => ['radix' => '36']],
            ['type' => 'json'],
        ],
    ]);
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())
        ->toBe(['type' => ['string', 'null']]);
    expect(app(TransformFactory::class)->pipeline($replica, $object, $keyMap))->toMatchArray([
        'transforms' => $chain,
        'transforms.field_0_0.type' => 'org.phpinnacle.toblerone.MoneyTransform$' . ucfirst($target->value),
        'transforms.field_0_0.fields' => 'amount',
        'transforms.field_0_0.currency' => 'KWD',
        'transforms.field_0_0.mode' => 'minor',
        'transforms.field_0_1.type' => 'org.phpinnacle.toblerone.RadixTransform$' . ucfirst($target->value),
        'transforms.field_0_1.fields' => 'amount:36',
        'transforms.field_0_2.type' => 'org.phpinnacle.toblerone.JsonTransform$' . ucfirst($target->value),
        'transforms.field_0_2.fields' => 'amount',
    ]);
})->with([
    'value' => [TransformTarget::Value, [], 'field_0_0,field_0_1,field_0_2,rename_values'],
    'key' => [TransformTarget::Key, ['amount' => 'result'], 'field_0_0,field_0_1,field_0_2,rename_keys,rename_values'],
]);

it('casts numeric sources to FLOAT64 before converting money to integer minor units', function () {
    $object = StructureItems::make(['fields' => ['amount' => Schema::fromArray(['type' => 'number'])]]);
    $mapping = new FieldMapping('amount', 'minor', [
        'transforms' => [
            ['type' => 'cast', 'data' => ['type' => 'float64']],
            ['type' => 'money', 'data' => ['currency' => 'BYN']],
        ],
    ]);
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())
        ->toBe(['type' => 'integer']);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'field_0_0,field_0_1,rename_values',
        'transforms.field_0_0.type' => 'org.apache.kafka.connect.transforms.Cast$Value',
        'transforms.field_0_0.spec' => 'amount:float64',
        'transforms.field_0_1.type' => 'org.phpinnacle.toblerone.MoneyTransform$Value',
        'transforms.field_0_1.fields' => 'amount',
        'transforms.field_0_1.currency' => 'BYN',
        'transforms.field_0_1.mode' => 'minor',
    ]);
});

it('inserts floating-point constants as FLOAT64 before converting money', function () {
    $object = StructureItems::make();
    $mapping = new FieldMapping(
        null,
        'minor',
        [
            'transforms' => [['type' => 'money', 'data' => ['currency' => 'BYN']]],
        ],
        0.29,
        FieldKind::Static,
    );
    $replica = new Replica(['schema' => $object->schema, 'mapping' => [$mapping]]);

    expect(app(ReplicaSchemaResolver::class)->outputSchema($replica, $mapping)?->toArray())
        ->toBe(['type' => 'integer']);
    expect(app(TransformFactory::class)->pipeline($replica, $object))->toMatchArray([
        'transforms' => 'field_0_0,field_0_0_cast,field_0_1,rename_values',
        'transforms.field_0_0.static.value' => '0.29',
        'transforms.field_0_0_cast.spec' => '__ferry_static_0:float64',
        'transforms.field_0_1.type' => 'org.phpinnacle.toblerone.MoneyTransform$Value',
        'transforms.field_0_1.fields' => '__ferry_static_0',
        'transforms.field_0_1.currency' => 'BYN',
        'transforms.field_0_1.mode' => 'minor',
    ]);
});

it('offers Toblerone transforms only for their supported input types', function (array $input, array $accepted) {
    foreach ([BooleanToString::make(), StringMap::make(), Toggle::make()] as $transform) {
        expect($transform->accepts(Schema::fromArray($input)))
            ->toBe(in_array($transform->getKey(), $accepted, true));
    }
})->with([
    'boolean' => [['type' => 'boolean'], ['boolean_to_string', 'toggle']],
    'nullable boolean' => [['type' => ['boolean', 'null']], ['boolean_to_string', 'toggle']],
    'text' => [['type' => 'string'], ['string_map']],
    'nullable text' => [['type' => ['string', 'null']], ['string_map']],
    'date' => [['type' => 'string', 'format' => 'date'], []],
    'UUID' => [['type' => 'string', 'format' => 'uuid'], ['string_map']],
    'integer' => [['type' => 'integer'], []],
    'union' => [['type' => ['boolean', 'string']], []],
]);

it('maps boolean schemas to exact string enums including empty strings and null', function () {
    $transform = BooleanToString::make();
    expect($transform->output(Schema::fromArray(['type' => ['boolean', 'null']]), [
        'true' => '',
        'false' => 'no',
    ])?->toArray())
        ->toBe(['type' => ['string', 'null'], 'enum' => ['', 'no', null], 'minLength' => 0, 'maxLength' => 2]);
    expect($transform->output(Schema::fromArray(['type' => 'boolean']), [
        'true' => 'same',
        'false' => 'same',
    ])?->toArray())
        ->toBe(['type' => 'string', 'enum' => ['same'], 'minLength' => 4, 'maxLength' => 4]);
    expect($transform->output(Schema::fromArray(['type' => 'boolean', 'enum' => [false]]), [
        'true' => 'yes',
        'false' => 'no',
    ])?->toArray())
        ->toBe(['type' => 'string', 'enum' => ['no'], 'minLength' => 2, 'maxLength' => 2]);
    expect($transform->output(Schema::fromArray(['type' => 'boolean']), []))->toBeNull();
});

it('inverts effective boolean enums and preserves nullability', function () {
    $input = Schema::fromArray([
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
            ->output(Schema::fromArray(['type' => 'boolean']), [])
            ->toArray(),
    )->toBe(['type' => 'boolean']);
});

it('projects exact one-pass string replacements and discards obsolete string constraints', function () {
    $input = Schema::fromArray([
        'type' => ['string', 'null'],
        'maxLength' => 9,
        'pattern' => '^[a-zA-Z]*$',
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
            ->output(Schema::fromArray(['type' => 'string', 'maxLength' => 4]), [
                'mapping' => ['new' => 'published', 'done' => ''],
            ])
            ?->toArray(),
    )
        ->toBe(['type' => 'string', 'maxLength' => 9]);
    expect(StringMap::make()->output($input, []))->toBeNull();
});

it('drops the source format when mapping formatted strings to plain text', function () {
    $input = Schema::fromArray(['type' => 'string', 'format' => 'email', 'enum' => ['user@example.com']]);

    expect(
        StringMap::make()
            ->output($input, ['mapping' => ['user@example.com' => 'ready']])
            ->toArray(),
    )->toBe([
        'type' => 'string',
        'enum' => ['ready'],
        'minLength' => 5,
        'maxLength' => 5,
    ]);
});

it('preserves empty and whitespace boolean labels through native forms', function () {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(BooleanToString::make()));
    $schema->fill(['true' => '', 'false' => '   ']);
    expect($schema->getState())->toBe(['true' => '', 'false' => '   ']);
    $schema->fill(['true' => [], 'false' => 'no']);
    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.true'),
        );
});

it('edits the replacement dictionary using native KeyValue while preserving exact text', function () {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(StringMap::make()));
    expect($schema->getComponent('mapping'))->toBeInstanceOf(KeyValue::class);
    $mapping = ['draft' => '', ' padded ' => '  ', 'a,b;c.d' => 'punctuation', '1' => 'one'];
    $schema->fill(['mapping' => $mapping]);
    expect($schema->getState())->toBe(['mapping' => $mapping]);
});

it('validates replacement dictionary values at the native form boundary', function (array $mapping) {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(StringMap::make()));
    $schema->fill(['mapping' => $mapping]);
    expect($schema->getState(...))
        ->toThrow(
            fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('data.mapping'),
        );
})->with([
    'empty dictionary' => [[]],
    'numeric replacement' => [['draft' => 1]],
    'boolean replacement' => [['draft' => true]],
    'null replacement' => [['draft' => null]],
]);

it('compiles the new Toblerone chain before renames without changing the key', function () {
    $object = StructureItems::make(['fields' => ['active' => Schema::fromArray(['type' => ['boolean', 'null']])]]);
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
