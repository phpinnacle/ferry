<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\CastTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\FilterTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\MaskFieldTransform;
use PHPinnacle\Ferry\Origins\Kafka\Transforms\SubstringTransform;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

require_once __DIR__ . '/../Fixtures/StructureItems.php';

uses(TestCase::class);

beforeEach(function () {
    FerryPlugin::make();
});

it('registers the built-in transforms through the UI registry independently of their handlers', function () {
    $registry = $this->app->make(TransformRegistry::class);

    expect($registry->get('cast'))
        ->toBeInstanceOf(Transformation::class)
        ->and(app(TransformFactory::class)->handler('cast'))
        ->toBeInstanceOf(CastTransform::class)
        ->and($registry->get('static_value'))
        ->toBeNull()
        ->and($registry->get('unknown'))
        ->toBeNull();
});

it('registers application transforms through the plugin', function () {
    FerryPlugin::make()->transforms(Transformation::make('trim')->label('Trim'));

    $registry = $this->app->make(TransformRegistry::class);

    expect($registry->get('trim'))
        ->not
        ->toBeNull()
        ->and(array_keys($registry->all()))
        ->toBe(['cast', 'filter', 'trim']);
});

it('casts a field to the selected column type', function (string $type, string $spec) {
    $cast = new CastTransform;

    expect(
        Cast::make()
            ->getOutputSchema(JsonSchema::fromArray(['type' => 'string']), ['type' => $type])
            ?->toArray(),
    )
        ->toBe(['type' => $type])
        ->and($cast->connector('cast_price', '_fld1', ['type' => $type]))
        ->toBe([
            'cast_price' => [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $spec,
            ],
        ]);
})->with([
    'string' => ['string', '_fld1:string'],
    'integer' => ['integer', '_fld1:int64'],
    'decimal' => ['number', '_fld1:float64'],
    'boolean' => ['boolean', '_fld1:boolean'],
]);

it('uses each cast result as the input to the next cast without resolving handlers', function (
    string $intermediate,
    string $result,
) {
    config(['phpinnacle-ferry.kafka.transforms' => []]);

    expect(app(TransformRegistry::class)->resultSchema(JsonSchema::fromArray(['type' => 'string']), [
        ['type' => 'cast', 'data' => ['type' => $intermediate]],
        ['type' => 'cast', 'data' => ['type' => $result]],
    ])?->toArray())
        ->toBe(['type' => $result]);
})->with([
    'integer to boolean' => ['integer', 'boolean'],
    'number to integer' => ['number', 'integer'],
    'integer to number' => ['integer', 'number'],
    'integer to integer' => ['integer', 'integer'],
]);

it('clears reference metadata when explicitly casting to a string', function () {
    $input = JsonSchema::fromArray([
        'type' => ['string', 'null'],
        'format' => 'uuid',
        'x-ferry-reference' => true,
    ]);
    $output = Cast::make()->getOutputSchema($input, ['type' => 'string']);

    expect($output->toArray())->toBe(['type' => ['string', 'null']]);
    expect($output->singleType())->toBe('string');
    expect(Trim::make()->accepts($output))->toBeTrue();
});

it('disables and rejects the current cast type in the native form', function (array $definition, string $current) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            Cast::make()->getSchema(null, JsonSchema::fromArray($definition)),
        );
    $schema->fill(['type' => $current]);

    expect($schema->getComponent('type')->isOptionDisabled($current, ''))->toBeTrue();
    expect($schema->getState(...))->toThrow(ValidationException::class);
})->with([
    [['type' => 'string'], 'string'],
    [['type' => 'integer'], 'integer'],
    [['type' => 'number'], 'number'],
    [['type' => 'boolean'], 'boolean'],
    [['type' => ['integer', 'null']], 'integer'],
]);

it('keeps all cast targets available when the current type is unknown or a union', function (array $definition) {
    $schema = Schema::make(SchemaHost::make())->components(
        Cast::make()->getSchema(null, JsonSchema::fromArray($definition)),
    );

    expect(array_keys($schema->getComponent('type')->getEnabledOptions()))->toBe([
        'string',
        'integer',
        'number',
        'boolean',
    ]);
})->with([
    [[]],
    [['type' => ['string', 'number', 'null']]],
    [['type' => 'string', 'format' => 'uuid', 'x-ferry-reference' => true]],
]);

it('keeps the Kafka null replacement default implicit and persists disabling it', function (?string $setting) {
    $schema = Schema::make(SchemaHost::make())
        ->statePath('data')
        ->components(
            Cast::make()->getSchema(null, JsonSchema::fromArray(['type' => 'boolean'])),
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
    $config = new CastTransform()->connector('cast', 'field', $expected)['cast'];
    expect($config['replace.null.with.default'] ?? 'true')->toBe($setting ?? 'true');
})->with([null, 'true', 'false']);

it('validates null replacement settings before dehydration', function () {
    $schema = Schema::make(SchemaHost::make())->statePath('data')->components(Cast::make()->getSchema(null));
    $schema->fill(['type' => 'string', 'replace_null_with_default' => 'invalid']);

    expect($schema->getState(...))->toThrow(ValidationException::class);
});

it('inserts a string constant without registering a transform', function () {
    $replica = new Replica([
        'schema' => [
            new FieldMapping(null, 'type', null, static: 'Customer', kind: FieldKind::Static),
        ],
    ]);
    $pipeline = new TransformFactory()->pipeline($replica, StructureItems::make());

    expect($pipeline)->toMatchArray([
        'transforms' => 'unwrap,rename_keys,rename_values,field_0_0',
        'transforms.field_0_0.type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
        'transforms.field_0_0.static.field' => 'type',
        'transforms.field_0_0.static.value' => 'Customer',
    ]);
});

it('casts a typed constant after inserting it', function () {
    $constant = true;
    $mapping = new FieldMapping(null, 'is_active', null, static: $constant, kind: FieldKind::Static);
    $replica = new Replica(['schema' => [$mapping]]);
    $transforms = app(TransformFactory::class)->pipeline($replica, StructureItems::make());

    expect($mapping->outputSchema()?->singleType())
        ->toBe('boolean')
        ->and($mapping->transforms)
        ->toBe([])
        ->and($transforms['transforms'])
        ->toBe('unwrap,rename_keys,rename_values,field_0_0,field_0_0_cast')
        ->and($transforms['transforms.field_0_0_cast.spec'])
        ->toBe('is_active:boolean');
});

it('registers optional installed field transforms through the public plugin API', function () {
    $registry = $this->app->make(TransformRegistry::class);
    expect($registry->get('substring'))
        ->toBeNull()
        ->and($registry->get('mask'))
        ->toBeNull()
        ->and($registry->get('trim'))
        ->toBeNull();

    FerryPlugin::make()->transforms(Trim::make(), Substring::make(), MaskField::make());

    expect(array_keys($registry->all()))->toBe([
        'cast',
        'filter',
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
    expect(new SubstringTransform()->connector('slice', '_description', $params))->toBe([
        'slice' => ['type' => 'org.phpinnacle.toblerone.SubstringTransform$Value', 'fields' => $fields],
    ]);
})->with([
    'remainder' => [['start' => '2'], '_description:2'],
    'bounded slice' => [['start' => '1', 'end' => '5'], '_description:1:5'],
    'zero end is retained' => [['start' => '0', 'end' => '0'], '_description:0:0'],
]);

it('masks a source field without applying the Apache transform to tombstones', function () {
    expect(new MaskFieldTransform()->connector('mask_email', '_fld1', [
        'replacement' => 'hidden',
    ]))->toBe([
        'mask_email' => [
            'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
            'fields' => '_fld1',
            'replacement' => 'hidden',
            'predicate' => 'tombstone',
            'negate' => 'true',
        ],
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
    Transformation $transform,
    array $params,
    ?array $expected,
) {
    $livewire = SchemaHost::make();
    $schema = Schema::make($livewire)->statePath('data')->components($transform->getSchema(null));
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

it('keeps description values immutable and settings forms independent', function () {
    $input = JsonSchema::fromArray(['type' => 'string', 'format' => 'email', 'maxLength' => 100]);
    $base = Transformation::make('custom');
    $definition = $base
        ->label('Custom transform')
        ->description('Custom description')
        ->icon(Heroicon::Scissors)
        ->color(fn () => 'success')
        ->inputSchema(['type' => 'string'])
        ->outputSchema(['type' => 'boolean'])
        ->schema([TextInput::make('value')]);

    expect($base->getLabel())
        ->toBe('custom')
        ->and($base->getDescription())
        ->toBeNull()
        ->and($base->getColor())
        ->toBe('primary')
        ->and($base->getSchema(null))
        ->toBe([])
        ->and($base->accepts(JsonSchema::fromArray(['type' => 'boolean'])))
        ->toBeTrue()
        ->and($base->getOutputSchema($input, []))
        ->toBe($input)
        ->and($definition->accepts(JsonSchema::fromArray(['type' => 'boolean'])))
        ->toBeFalse()
        ->and($definition->accepts($input))
        ->toBeTrue()
        ->and($definition->getOutputSchema($input, [])?->toArray())
        ->toBe(['type' => 'boolean'])
        ->and($definition->getLabel())
        ->toBe('Custom transform')
        ->and($definition->getDescription())
        ->toBe('Custom description')
        ->and($definition)
        ->toBeInstanceOf(HasColor::class)
        ->and($definition->getColor())
        ->toBe('success')
        ->and($definition->getIcon())
        ->toBe(Heroicon::Scissors);

    $first = Schema::make(SchemaHost::make())->components($definition->getSchema(null));
    $first->fill(['value' => 'First']);
    $second = Schema::make(SchemaHost::make())->components($definition->getSchema(null));
    $second->fill(['value' => 'Second']);

    expect($first->getState())->toBe(['value' => 'First']);
    expect($second->getState())->toBe(['value' => 'Second']);
});

it('preserves Filament palettes and nullable colors', function () {
    $definition = Trim::make()->color(Color::Emerald);

    expect($definition->getColor())
        ->toBe(Color::Emerald)
        ->and($definition->color(null)->getColor())
        ->toBeNull()
        ->and($definition->getColor())
        ->toBe(Color::Emerald);
});

it('preserves nullability and replaces value constraints in built-in output schemas', function (
    Transformation $transform,
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

    expect($transform->getOutputSchema($input, $params)?->toArray())->toBe(['type' => ['string', 'null']]);
})->with([
    'trim' => [Trim::make(), []],
    'substring' => [Substring::make(), ['start' => '1']],
    'mask' => [MaskField::make(), ['replacement' => 'hidden']],
    'cast' => [Cast::make(), ['type' => 'string']],
]);

it('uses schema requirements for format and length dependent availability', function () {
    $transform = Transformation::make('email')
        ->inputSchema(['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 100]);

    expect($transform->accepts(JsonSchema::fromArray(['type' => 'string', 'format' => 'email', 'maxLength' => 80])))
        ->toBeTrue()
        ->and($transform->accepts(JsonSchema::fromArray(['type' => 'string', 'maxLength' => 80])))
        ->toBeFalse()
        ->and($transform->accepts(JsonSchema::fromArray(['type' => 'string', 'format' => 'email', 'maxLength' => 200])))
        ->toBeFalse();
});

it('passes the complete preceding output schema to callbacks in an ordered chain', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(Transformation::make('limit')
        ->inputSchema(['type' => 'string'])
        ->outputSchema(fn (JsonSchema $input, array $params) => [
            ...$input->toArray(),
            'maxLength' => (int) $params['length'],
        ]));
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

it('builds editor descriptions without resolving execution handlers', function () {
    app()->bind(CastTransform::class, fn () => throw new LogicException('The editor must not resolve handlers.'));

    expect(app(TransformRegistry::class)->getOrFail('cast')->getSchema(null)[0])->toBeInstanceOf(Select::class);
});

it('builds a configured pipeline without registering editor descriptions', function () {
    config(['phpinnacle-ferry.kafka.transforms.headless' => FilterTransform::class]);
    $handler = new FilterTransform;
    app()->bind(FilterTransform::class, fn () => $handler);
    $registry = new TransformRegistry;
    app()->bind(
        TransformRegistry::class,
        fn () => throw new LogicException('The worker must not resolve editor descriptions.'),
    );
    $factory = app(TransformFactory::class);
    $replica = new Replica;
    $replica->forceFill([
        'schema' => [new FieldMapping(
            '_marked',
            null,
            JsonSchema::fromArray(['type' => 'boolean']),
            transforms: [new TransformSetting('headless', [
                'mode' => 'exclude',
                'condition' => '@ == true',
                'missing_or_null_behavior' => 'include',
            ])],
        )],
    ]);

    expect($registry->get('headless'))
        ->toBeNull()
        ->and($factory->handler('headless'))
        ->toBe($handler)
        ->and(
            $factory->pipeline($replica, StructureItems::make([
                'fields' => ['_marked' => JsonSchema::fromArray(['type' => 'boolean'])],
                'sources' => [],
            ]))['transforms.field_0_0.filter.condition'],
        )
        ->toBe("$[?(@['_marked'] == true)]");
});

it('persists and executes static chains on their destination without renaming', function () {
    $row = [
        'kind' => 'static',
        'target' => 'discount',
        'static' => '10',
        'result' => ['type' => 'integer'],
        'transforms' => [['type' => 'cast', 'data' => ['type' => 'integer']]],
    ];
    $mapping = FieldMapping::fromArray($row);
    expect($mapping->jsonSerialize())
        ->toBe($row)
        ->and($mapping->sourceSchema()?->singleType())
        ->toBe('string')
        ->and($mapping->outputSchema()?->singleType())
        ->toBe('integer');
    app()->forgetInstance(TransformRegistry::class);
    $pipeline = app(TransformFactory::class)->pipeline(new Replica(['schema' => [$mapping]]), StructureItems::make());
    expect($pipeline['transforms'])
        ->toBe('unwrap,rename_keys,rename_values,field_0_0,field_0_1')
        ->and($pipeline['transforms.field_0_0.static.field'])
        ->toBe('discount')
        ->and($pipeline['transforms.field_0_0.static.value'])
        ->toBe('10')
        ->and($pipeline['transforms.field_0_1.spec'])
        ->toBe('discount:int64');
});
