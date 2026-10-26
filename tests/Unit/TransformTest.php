<?php

use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\KafkaType;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\Filter;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use Tests\TestCase;

require_once __DIR__ . '/../Fakes/Transform.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('registers the built-in transforms in the shared registry', function () {
    $registry = $this->app->make(TransformRegistry::class);

    expect($registry->get('cast'))
        ->toBeInstanceOf(Cast::class)
        ->and($registry->get('static_value'))
        ->toBeNull()
        ->and($registry->get('unknown'))
        ->toBeNull();
});

it('resolves only the configured transform classes through the container', function () {
    $transform = new FakeTransform('configured');
    app()->instance(FakeTransform::class, $transform);
    config(['phpinnacle-ferry.kafka.transforms' => [FakeTransform::class, Cast::class]]);
    app()->forgetInstance(TransformRegistry::class);

    $registry = app(TransformRegistry::class);

    expect(array_keys($registry->all()))->toBe(['configured', 'cast']);
    expect($registry->get('configured'))->toBe($transform);
    expect($registry->get('cast'))->toBeInstanceOf(Cast::class);
});

it('registers application transforms through the shared registry', function () {
    app(TransformRegistry::class)->register(new FakeTransform('trim', block: Block::make('trim')->label('Trim')));

    $registry = $this->app->make(TransformRegistry::class);

    expect($registry->get('trim'))
        ->not
        ->toBeNull()
        ->and(array_keys($registry->all()))
        ->toBe(['cast', 'filter', 'insert_uuid', 'trim']);
});

it('keeps key and value transformation chains on their declared record targets', function () {
    $value = new FakeTransform('custom');
    $key = new FakeTransform('custom', target: TransformTarget::Key);
    expect($value->getTarget())->toBe(TransformTarget::Value);
    expect($key->getTarget())->toBe(TransformTarget::Key);
    $registry = new TransformRegistry;
    $registry->register($key);
    $schema = JsonSchema::fromArray(['type' => 'string']);
    $settings = [['type' => 'custom']];
    expect($registry->resultSchema($schema, $settings))->toBeNull();
    expect($registry->resultSchema($schema, $settings, TransformTarget::Key)?->toArray())->toBe($schema->toArray());
});

it('uses translated Kafka type labels and stores their native string values', function (string $locale, array $labels) {
    app()->setLocale($locale);
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make()));
    $schema->fill(['type' => 'int16']);
    $field = $schema->getComponent('type');

    expect($field->getOptions())->toBe($labels);
    expect($field->getState())->toBe(KafkaType::Int16);
    expect($schema->getState())->toBe(['type' => 'int16']);
})->with([
    'English' => [
        'en',
        [
            'string' => 'String',
            'int8' => '8-bit integer',
            'int16' => '16-bit integer',
            'int32' => '32-bit integer',
            'int64' => '64-bit integer',
            'float32' => '32-bit floating-point number',
            'float64' => '64-bit floating-point number',
            'boolean' => 'Boolean',
        ],
    ],
    'Russian' => [
        'ru',
        [
            'string' => 'Строка',
            'int8' => 'Целое число (8 бит)',
            'int16' => 'Целое число (16 бит)',
            'int32' => 'Целое число (32 бита)',
            'int64' => 'Целое число (64 бита)',
            'float32' => 'Число с плавающей точкой (32 бита)',
            'float64' => 'Число с плавающей точкой (64 бита)',
            'boolean' => 'Логическое значение',
        ],
    ],
]);

it('casts a field to the selected column type', function (string $type, string $schemaType) {
    $cast = new Cast;

    expect(
        Cast::make()
            ->output(JsonSchema::fromArray(['type' => 'string']), ['type' => $type])
            ?->toArray(),
    )
        ->toBe(['type' => $schemaType])
        ->and($cast->connector('_fld1', ['type' => $type]))
        ->toBe([
            'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
            'spec' => '_fld1:' . $type,
        ]);
})->with([
    'string' => ['string', 'string'],
    'int8' => ['int8', 'integer'],
    'int16' => ['int16', 'integer'],
    'int32' => ['int32', 'integer'],
    'int64' => ['int64', 'integer'],
    'float32' => ['float32', 'number'],
    'float64' => ['float64', 'number'],
    'boolean' => ['boolean', 'boolean'],
]);

it('uses each cast result as the input to the next cast in the shared registry', function (
    string $intermediate,
    string $result,
    string $schemaType,
) {
    expect(app(TransformRegistry::class)->resultSchema(JsonSchema::fromArray(['type' => 'string']), [
        ['type' => 'cast', 'data' => ['type' => $intermediate]],
        ['type' => 'cast', 'data' => ['type' => $result]],
    ])?->toArray())
        ->toBe(['type' => $schemaType]);
})->with([
    'integer to boolean' => ['int64', 'boolean', 'boolean'],
    'number to integer' => ['float64', 'int32', 'integer'],
    'integer to number' => ['int32', 'float32', 'number'],
    'integer to integer' => ['int32', 'int64', 'integer'],
]);

it('clears reference metadata when explicitly casting to a string', function () {
    $input = JsonSchema::fromArray([
        'type' => ['string', 'null'],
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    $output = Cast::make()->output($input, ['type' => 'string']);

    expect($output->toArray())->toBe(['type' => ['string', 'null']]);
    expect($output->singleType())->toBe('string');
    expect(Trim::make()->accepts($output))->toBeTrue();
});

it('disables and rejects the current cast type in the native form', function (array $definition, string $current) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(Cast::make(), JsonSchema::fromArray($definition)),
        );
    $schema->fill(['type' => $current]);

    expect($schema->getComponent('type')->isOptionDisabled($current, ''))->toBeTrue();
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    [['type' => 'string'], 'string'],
    [['type' => 'boolean'], 'boolean'],
    [['type' => ['boolean', 'null']], 'boolean'],
]);

it('keeps all cast targets available when the current type is unknown or a union', function (array $definition) {
    $schema = Schema::make(SchemaHost::make())->components(
        SchemaHost::transformSchema(Cast::make(), JsonSchema::fromArray($definition)),
    );

    expect(array_keys($schema->getComponent('type')->getEnabledOptions()))->toBe([
        'string',
        'int8',
        'int16',
        'int32',
        'int64',
        'float32',
        'float64',
        'boolean',
    ]);
})->with([
    [[]],
    [['type' => ['string', 'number', 'null']]],
    [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true]],
]);

it('validates numeric cast targets and preserves nullable output schemas', function (string $type, string $schemaType) {
    $input = JsonSchema::fromArray(['type' => [$schemaType, 'null'], 'minimum' => 0]);
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make(), $input));
    $schema->fill(['type' => $type]);

    expect($schema->getState())->toBe(['type' => $type]);
    expect(Cast::make()->output($input, ['type' => $type])?->toArray())
        ->toBe(['type' => [$schemaType, 'null']]);
})->with([
    ['int8', 'integer'],
    ['int16', 'integer'],
    ['int32', 'integer'],
    ['int64', 'integer'],
    ['float32', 'number'],
    ['float64', 'number'],
]);

it('rejects unsupported cast targets in the native form', function (string $type) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make()));
    $schema->fill(['type' => $type]);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with(['integer', 'number', 'bytes', 'array', 'object']);

it('keeps the Kafka null replacement default implicit and persists disabling it', function (?string $setting) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(Cast::make(), JsonSchema::fromArray(['type' => 'boolean'])),
        );
    $params = ['type' => 'string'];
    if ($setting !== null) {
        $params['replace_null_with_default'] = $setting;
    }
    $schema->fill($params);

    expect($schema->getComponent('replace_null_with_default')->getState())->toBe($setting ?? 'true');
    $expected = ['type' => 'string'];
    if ($setting === 'false') {
        $expected['replace_null_with_default'] = 'false';
    }
    expect($schema->getState())->toBe($expected);
    $config = new Cast()->connector('field', $expected);
    expect($config['replace.null.with.default'] ?? 'true')->toBe($setting ?? 'true');
})->with([null, 'true', 'false']);

it('validates null replacement settings before dehydration', function () {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make()));
    $schema->fill(['type' => 'string', 'replace_null_with_default' => 'invalid']);

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('inserts a string constant without registering a transform', function () {
    $replica = new Replica([
        'mapping' => [
            new FieldMapping(source: null, target: 'type', static: 'Customer', kind: FieldKind::Static),
        ],
    ]);
    $pipeline = app(TransformFactory::class)->pipeline($replica, StructureItems::make());

    expect($pipeline)->toMatchArray([
        'transforms' => 'rename_values,field_0_0',
        'transforms.field_0_0.type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
        'transforms.field_0_0.static.field' => 'type',
        'transforms.field_0_0.static.value' => 'Customer',
    ]);
});

it('casts a typed constant after inserting it', function () {
    $constant = true;
    $mapping = new FieldMapping(source: null, target: 'is_active', static: $constant, kind: FieldKind::Static);
    $replica = new Replica(['mapping' => [$mapping]]);
    $transforms = app(TransformFactory::class)->pipeline($replica, StructureItems::make());

    expect($mapping->staticSchema()?->singleType())
        ->toBe('boolean')
        ->and($mapping->transforms())
        ->toBe([])
        ->and($transforms['transforms'])
        ->toBe('rename_values,field_0_0,field_0_0_cast')
        ->and($transforms['transforms.field_0_0_cast.spec'])
        ->toBe('is_active:boolean');
});

it('registers optional installed field transforms through the shared registry', function () {
    $registry = $this->app->make(TransformRegistry::class);
    expect($registry->get('substring'))
        ->toBeNull()
        ->and($registry->get('mask'))
        ->toBeNull()
        ->and($registry->get('trim'))
        ->toBeNull();

    app(TransformRegistry::class)->register(Trim::make(), Substring::make(), MaskField::make());

    expect(array_keys($registry->all()))->toBe([
        'cast',
        'filter',
        'insert_uuid',
        'trim',
        'substring',
        'mask',
    ]);
    expect($registry->resultSchema(JsonSchema::fromArray(['type' => 'string']), [
        ['type' => 'trim', 'data' => []],
        ['type' => 'substring', 'data' => ['start' => '0']],
        ['type' => 'mask', 'data' => ['replacement' => '***']],
    ])?->toArray())->toBe(['type' => 'string']);
    expect($registry->resultSchema(JsonSchema::fromArray(['type' => 'boolean']), [
        ['type' => 'mask', 'data' => ['replacement' => '***']],
    ]))->toBeNull();
});

it('serializes substring indexes using the installed Toblerone field format', function (array $params, string $fields) {
    expect(new Substring()->connector('_description', $params))->toBe([
        'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
        'fields' => $fields,
    ]);
})->with([
    'remainder' => [['start' => '2'], '_description:2'],
    'bounded slice' => [['start' => '1', 'end' => '5'], '_description:1:5'],
    'zero end is retained' => [['start' => '0', 'end' => '0'], '_description:0:0'],
]);

it('masks a source field without applying the Apache transform to tombstones', function () {
    expect(new MaskField()->connector('_fld1', [
        'replacement' => 'hidden',
    ]))->toBe([
        'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
        'fields' => '_fld1',
        'replacement' => 'hidden',
        'predicate' => 'tombstone',
        'negate' => 'true',
    ]);
});

it('only applies text transforms to native string inputs', function (array $definition, bool $allowed) {
    foreach ([Trim::make(), Substring::make(), MaskField::make()] as $description) {
        expect($description->accepts(JsonSchema::fromArray($definition)))->toBe($allowed);
    }
})->with([
    'string' => [['type' => 'string'], true],
    'nullable string' => [['type' => ['string', 'null']], true],
    'ordinary UUID' => [['type' => 'string', 'format' => 'uuid'], true],
    'reference' => [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true], false],
    'date-time' => [['type' => 'string', 'format' => 'date-time'], false],
    'integer' => [['type' => 'integer'], false],
    'number' => [['type' => 'number'], false],
    'boolean' => [['type' => 'boolean'], false],
    'array' => [['type' => 'array'], false],
    'unknown' => [[], false],
]);

it('dehydrates native settings forms into string parameters and omits an empty end', function (
    Transform $transform,
    array $params,
    ?array $expected,
) {
    $livewire = SchemaHost::make();
    $schema = Schema::make($livewire)->statePath('data')->components(SchemaHost::transformSchema($transform));
    $schema->fill($params === [] ? null : $params);

    if ($params !== []) {
        $livewire->data = $params;
    }

    if ($expected === null) {
        expect($schema->getState(...))->toThrow(ValidationException::class);

        return;
    }

    expect($schema->getState())->toBe($expected);
})->with([
    'default indexes' => [Substring::make(), [], ['start' => '0']],
    'numeric indexes' => [Substring::make(), ['start' => 1, 'end' => 4], ['start' => '1', 'end' => '4']],
    'empty end' => [Substring::make(), ['start' => '2', 'end' => null], ['start' => '2']],
    'zero end' => [Substring::make(), ['start' => 0, 'end' => 0], ['start' => '0', 'end' => '0']],
    'invalid form indexes' => [Substring::make(), ['start' => 4, 'end' => 1], null],
    'default mask' => [MaskField::make(), [], ['replacement' => '***']],
    'invalid mask form' => [MaskField::make(), ['replacement' => ''], null],
    'cleared start' => [Substring::make(), ['start' => null], null],
    'negative start' => [Substring::make(), ['start' => '-1'], null],
    'fractional start' => [Substring::make(), ['start' => '1.5'], null],
    'non-scalar start' => [Substring::make(), ['start' => []], null],
    'index exceeds Java integer' => [Substring::make(), ['start' => '2147483648'], null],
]);

it('keeps settings forms independent when a transformation is reused', function () {
    $transform = MaskField::make();
    $first = Schema::make(SchemaHost::make())->statePath('data')->components(SchemaHost::transformSchema($transform));
    $first->fill(['replacement' => 'First']);
    $second = Schema::make(SchemaHost::make())->statePath('data')->components(SchemaHost::transformSchema($transform));
    $second->fill(['replacement' => 'Second']);

    expect($first->getState())->toBe(['replacement' => 'First']);
    expect($second->getState())->toBe(['replacement' => 'Second']);
});

it('preserves nullability and replaces value constraints in built-in output schemas', function (
    Transform $transform,
    array $params,
) {
    $input = JsonSchema::fromArray([
        'type' => ['string', 'null'],
        'format' => 'email',
        'enum' => ['user@example.com', null],
        'minLength' => 10,
        'maxLength' => 100,
        'pattern' => '@',
    ]);

    expect($transform->output($input, $params)?->toArray())->toBe(['type' => ['string', 'null']]);
})->with([
    'trim' => [Trim::make(), []],
    'substring' => [Substring::make(), ['start' => '1']],
    'mask' => [MaskField::make(), ['replacement' => 'hidden']],
    'cast' => [Cast::make(), ['type' => 'string']],
]);

it('passes the complete preceding output schema to callbacks in an ordered chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        new FakeTransform(
            'limit',
            accepts: fn (JsonSchema $input) => $input->singleType() === 'string',
            output: fn (JsonSchema $input, array $params) => JsonSchema::fromArray([
                ...$input->toArray(),
                'maxLength' => (int) $params['length'],
            ]),
        ),
    );
    $input = JsonSchema::fromArray(['type' => 'string', 'format' => 'email']);

    expect($registry->resultSchema($input, []))->toBe($input);
    expect(
        $registry
            ->resultSchema($input, [
                ['type' => 'limit', 'data' => ['length' => '80']],
                ['type' => 'limit', 'data' => ['length' => '40']],
            ])
            ?->toArray(),
    )->toBe(['type' => 'string', 'format' => 'email', 'maxLength' => 40]);
    expect($registry->resultSchema($input, [['type' => 'cast']]))->toBeNull();
});

it('compiles a custom registered transform without building its settings form', function () {
    $registry = app(TransformRegistry::class);
    $transform = new FakeTransform(
        'headless',
        block: Block::make('headless')->schema(fn () => throw new LogicException('The worker must not build a form.')),
        connector: fn (string $field, array $params) => Filter::make()->connector($field, $params),
    );
    $registry->register($transform);
    $factory = app(TransformFactory::class);
    $replica = new Replica;
    $replica->forceFill([
        'mapping' => [
            new FieldMapping(source: '_marked', target: 'is_active', options: [
                'transforms' => [[
                    'type' => 'headless',
                    'data' => [
                        'mode' => 'exclude',
                        'condition' => '@ == true',
                        'missing_or_null_behavior' => 'include',
                    ],
                ]],
            ]),
            new FieldMapping(source: 'id', target: 'id'),
        ],
    ]);

    $object = StructureItems::make([
        'primary_key' => ['id'],
        'fields' => [
            '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
            'id' => JsonSchema::fromArray(['type' => 'integer']),
        ],
        'sources' => [],
    ]);
    expect($registry->get('headless'))
        ->toBe($transform)
        ->and(
            $factory->pipeline($replica, $object)['transforms.field_0_0.filter.condition'],
        )
        ->toBe("$[?(@['_marked'] == true)]");
});

it('persists and executes static chains on their destination without renaming', function () {
    $row = [
        'kind' => 'static',
        'target' => 'discount',
        'static' => '10',
        'options' => ['transforms' => [['type' => 'cast', 'data' => ['type' => 'int64']]]],
    ];
    $mapping = FieldMapping::fromArray($row);
    expect($mapping->jsonSerialize())
        ->toBe($row)
        ->and($mapping->staticSchema()?->singleType())
        ->toBe('string')
        ->and(app(TransformRegistry::class)->mappingSchema($mapping, JsonSchema::fromArray([]))?->singleType())
        ->toBe('integer');
    $pipeline = app(TransformFactory::class)->pipeline(new Replica(['mapping' => [$mapping]]), StructureItems::make());
    expect($pipeline['transforms'])
        ->toBe('rename_values,field_0_0,field_0_1')
        ->and($pipeline['transforms.field_0_0.static.field'])
        ->toBe('discount')
        ->and($pipeline['transforms.field_0_0.static.value'])
        ->toBe('10')
        ->and($pipeline['transforms.field_0_1.spec'])
        ->toBe('discount:int64');
});

it('rejects a missing transform while compiling a pipeline', function () {
    $replica = new Replica(['mapping' => [
        new FieldMapping(source: 'name', target: 'name', options: ['transforms' => [['type' => 'unknown']]]),
    ]]);

    expect(fn () => app(TransformFactory::class)->pipeline($replica, StructureItems::make()))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.replica.errors.transform_missing', [
            'transform' => 'unknown',
        ]));
});
