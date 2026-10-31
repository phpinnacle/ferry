<?php

use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\KafkaType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\Filter;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Jason\Schema;
use Tests\TestCase;

require_once __DIR__ . '/../Fakes/Transform.php';

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

it('resolves only the configured transform classes through the container', function () {
    $transform = new FakeTransform('configured');
    app()->instance(FakeTransform::class, $transform);
    config(['phpinnacle-ferry.kafka.transforms' => [FakeTransform::class, Cast::class]]);
    app()->forgetInstance(TransformRegistry::class);

    $registry = app(TransformRegistry::class);

    expect(array_keys($registry->all()))->toBe(['configured', 'cast']);
    expect($registry->find('configured'))->toBe($transform);
    expect($registry->find('missing'))->toBeNull();
    expect($registry->get('configured'))->toBe($transform);
    expect($registry->find('cast'))->toBeInstanceOf(Cast::class);
});

it('resolves transformation chains only within their declared scope', function () {
    $transform = new FakeTransform('custom', scope: TransformScope::Record);
    $registry = new TransformRegistry;
    $registry->register($transform);
    $schema = Schema::fromArray(['type' => 'string']);
    $settings = [new TransformSetting('custom')];
    $schemas = new ReplicaSchemaResolver($registry);
    expect($schemas->resultSchema($schema, $settings))->toBeNull();
    expect($schemas->resultSchema($schema, $settings, TransformScope::Record)?->toArray())->toBe($schema->toArray());
});

it('uses translated Kafka type labels and stores their native string values', function (string $locale, array $labels) {
    app()->setLocale($locale);
    $schema = FilamentSchema::make(SchemaHost::make())
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
            ->output(Schema::fromArray(['type' => 'string']), ['type' => $type])
            ?->toArray(),
    )
        ->toBe(['type' => $schemaType])
        ->and($cast->connector(TransformTarget::Value, ['field' => '_fld1', 'type' => $type]))
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

it('clears reference metadata when explicitly casting to a string', function () {
    $input = Schema::fromArray([
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
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(Cast::make(), Schema::fromArray($definition)),
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
    $schema = FilamentSchema::make(SchemaHost::make())->components(
        SchemaHost::transformSchema(Cast::make(), Schema::fromArray($definition)),
    );

    expect($schema->getComponent('type')->getEnabledOptions())->toBe($schema->getComponent('type')->getOptions());
})->with([
    [[]],
    [['type' => ['string', 'number', 'null']]],
    [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true]],
]);

it('validates numeric cast targets and preserves nullable output schemas', function (string $type, string $schemaType) {
    $input = Schema::fromArray(['type' => [$schemaType, 'null'], 'minimum' => 0]);
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make(), $input));
    $schema->fill(['type' => $type]);

    expect($schema->getState())->toBe(['type' => $type]);
    expect(Cast::make()->output($input, ['type' => $type])?->toArray())
        ->toBe(['type' => [$schemaType, 'null']]);
})->with([
    'integer' => ['int64', 'integer'],
    'number' => ['float64', 'number'],
]);

it('rejects unsupported cast targets in the native form', function (string $type) {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema(Cast::make()));
    $schema->fill(['type' => $type]);

    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with(['integer', 'array']);

it('keeps the Kafka null replacement default implicit and persists disabling it', function (?string $setting) {
    $schema = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            SchemaHost::transformSchema(Cast::make(), Schema::fromArray(['type' => 'boolean'])),
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
    $config = new Cast()->connector(TransformTarget::Value, ['field' => 'field'] + $expected);
    expect($config['replace.null.with.default'] ?? 'true')->toBe($setting ?? 'true');
})->with([null, 'true', 'false']);

it('validates null replacement settings before dehydration', function () {
    $schema = FilamentSchema::make(SchemaHost::make())
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
        'transforms' => 'field_0_0,rename_values',
        'transforms.field_0_0.type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
        'transforms.field_0_0.static.field' => '__ferry_static_0',
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
        ->toBe('field_0_0,field_0_0_cast,rename_values')
        ->and($transforms['transforms.field_0_0_cast.spec'])
        ->toBe('__ferry_static_0:boolean');
});

it('registers optional installed field transforms through the shared registry', function () {
    $registry = $this->app->make(TransformRegistry::class);
    $registry->register(Trim::make(), Substring::make(), MaskField::make());
    expect(app(ReplicaSchemaResolver::class)->resultSchema(Schema::fromArray(['type' => 'string']), [
        new TransformSetting('trim'),
        new TransformSetting('substring', ['start' => '0']),
        new TransformSetting('mask', ['replacement' => '***']),
    ])?->toArray())->toBe(['type' => 'string']);
    expect(app(ReplicaSchemaResolver::class)->resultSchema(Schema::fromArray(['type' => 'boolean']), [
        new TransformSetting('mask', ['replacement' => '***']),
    ]))->toBeNull();
});

it('serializes substring indexes using the installed Toblerone field format', function (array $params, string $fields) {
    expect(new Substring()->connector(TransformTarget::Value, ['field' => '_description'] + $params))->toBe([
        'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
        'fields' => $fields,
    ]);
})->with([
    'remainder' => [['start' => '2'], '_description:2'],
    'bounded slice' => [['start' => '1', 'end' => '5'], '_description:1:5'],
    'zero end is retained' => [['start' => '0', 'end' => '0'], '_description:0:0'],
]);

it('masks a source field without applying the Apache transform to tombstones', function () {
    expect(new MaskField()->connector(TransformTarget::Value, ['field' => '_fld1', 'replacement' => 'hidden']))->toBe([
        'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
        'fields' => '_fld1',
        'replacement' => 'hidden',
        'predicate' => 'tombstone',
        'negate' => 'true',
    ]);
});

it('only applies text transforms to native string inputs', function (array $definition, bool $allowed) {
    foreach ([Trim::make(), Substring::make(), MaskField::make()] as $description) {
        expect($description->accepts(Schema::fromArray($definition)))->toBe($allowed);
    }
})->with([
    'string' => [['type' => 'string'], true],
    'nullable string' => [['type' => ['string', 'null']], true],
    'ordinary UUID' => [['type' => 'string', 'format' => 'uuid'], true],
    'reference' => [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true], false],
    'date-time' => [['type' => 'string', 'format' => 'date-time'], false],
    'date' => [['type' => 'string', 'format' => 'date'], false],
    'time' => [['type' => 'string', 'format' => 'time'], false],
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
    $schema = FilamentSchema::make($livewire)->statePath('data')->components(SchemaHost::transformSchema($transform));
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
    'non-string mask form' => [MaskField::make(), ['replacement' => 123], null],
    'cleared start' => [Substring::make(), ['start' => null], null],
    'negative start' => [Substring::make(), ['start' => '-1'], null],
    'fractional start' => [Substring::make(), ['start' => '1.5'], null],
    'non-scalar start' => [Substring::make(), ['start' => []], null],
    'index exceeds Java integer' => [Substring::make(), ['start' => '2147483648'], null],
]);

it('keeps settings forms independent when a transformation is reused', function () {
    $transform = MaskField::make();
    $first = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema($transform));
    $first->fill(['replacement' => 'First']);
    $second = FilamentSchema::make(SchemaHost::make())
        ->statePath('data')
        ->components(SchemaHost::transformSchema($transform));
    $second->fill(['replacement' => 'Second']);

    expect($first->getState())->toBe(['replacement' => 'First']);
    expect($second->getState())->toBe(['replacement' => 'Second']);
});

it('preserves nullability and replaces value constraints in built-in output schemas', function (
    Transform $transform,
    array $params,
) {
    $input = Schema::fromArray([
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
    $seen = [];
    $registry->register(
        new FakeTransform(
            'limit',
            accepts: fn (Schema $input) => $input->singleType() === 'string',
            output: function (Schema $input, array $params) use (&$seen) {
                $seen[] = $input->toArray();

                return Schema::fromArray([...$input->toArray(), 'maxLength' => (int) $params['length']]);
            },
        ),
    );
    $input = Schema::fromArray(['type' => 'string', 'format' => 'email']);

    expect(app(ReplicaSchemaResolver::class)->resultSchema($input, []))->toBe($input);
    expect(
        app(ReplicaSchemaResolver::class)
            ->resultSchema($input, [
                new TransformSetting('limit', ['length' => '80']),
                new TransformSetting('limit', ['length' => '40']),
            ])
            ?->toArray(),
    )->toBe(['type' => 'string', 'format' => 'email', 'maxLength' => 40]);
    expect($seen)->toBe([
        ['type' => 'string', 'format' => 'email'],
        ['type' => 'string', 'format' => 'email', 'maxLength' => 80],
    ]);
});

it('compiles a custom registered transform without building its settings form', function () {
    $registry = app(TransformRegistry::class);
    $transform = new FakeTransform(
        'headless',
        block: InlineBlock::make('headless')->schema(
            fn () => throw new LogicException('The worker must not build a form.'),
        ),
        connector: fn (TransformTarget $target, array $params) => Filter::make()->connector($target, $params),
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
            '_marked' => Schema::fromArray(['type' => 'boolean']),
            'id' => Schema::fromArray(['type' => 'integer']),
        ],
        'sources' => [],
    ]);
    expect($registry->find('headless'))
        ->toBe($transform)
        ->and(
            $factory->pipeline($replica, $object)['transforms.field_0_0.filter.condition'],
        )
        ->toBe("$[?(@['_marked'] == true)]");
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
