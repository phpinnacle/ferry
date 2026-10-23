<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Ferry\Transforms\CastTransform;
use PHPinnacle\Ferry\Transforms\FilterTransform;
use PHPinnacle\Ferry\Transforms\MaskFieldTransform;
use PHPinnacle\Ferry\Transforms\SubstringTransform;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

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

it('casts a field to the selected column type', function (ColumnType $type, string $spec) {
    $cast = new CastTransform;

    expect(
        Cast::make()
            ->getOutputSchema(JsonSchema::fromArray(['type' => 'string']), ['type' => $type->value])
            ?->toArray(),
    )
        ->toBe($type->jsonSchema())
        ->and($cast->connector('cast_price', '_fld1', ['type' => $type->value]))
        ->toBe([
            'cast_price' => [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $spec,
            ],
        ]);
})->with([
    'string' => [ColumnType::String, '_fld1:string'],
    'integer' => [ColumnType::Integer, '_fld1:int64'],
    'decimal' => [ColumnType::Decimal, '_fld1:float64'],
    'boolean' => [ColumnType::Boolean, '_fld1:boolean'],
]);

it('uses each cast result as the input to the next cast without resolving handlers', function (
    string $intermediate,
    ColumnType $result,
) {
    config(['phpinnacle-ferry.transforms' => []]);

    expect(app(TransformRegistry::class)->resultSchema(JsonSchema::fromArray(['type' => 'string']), [
        ['key' => 'cast', 'params' => ['type' => $intermediate]],
        ['key' => 'cast', 'params' => ['type' => $result->value]],
    ])?->toArray())
        ->toBe($result->jsonSchema());
})->with([
    'integer to boolean' => ['integer', ColumnType::Boolean],
    'decimal to integer' => ['decimal', ColumnType::Integer],
    'integer to decimal' => ['integer', ColumnType::Decimal],
    'integer to integer' => ['integer', ColumnType::Integer],
]);

it('inserts a string constant without registering a transform', function () {
    $sync = new Sync([
        'schema' => [
            new FieldMapping(null, 'type', null, static: 'Customer', kind: FieldKind::Static),
        ],
    ]);
    $pipeline = new TransformFactory()->pipeline($sync, new ConnectionMetadata);

    expect($pipeline)->toBe([
        'before' => [],
        'after' => ['field_0_0' => [
            'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
            'static.field' => 'type',
            'static.value' => 'Customer',
        ]],
    ]);
});

it('casts a typed constant after inserting it', function () {
    $constant = true;
    $mapping = new FieldMapping(null, 'is_active', null, static: $constant, kind: FieldKind::Static);
    $sync = new Sync(['schema' => [$mapping]]);
    $transforms = app(TransformFactory::class)->pipeline($sync, new ConnectionMetadata)['after'];

    expect($mapping->type())
        ->toBe(ColumnType::Boolean)
        ->and($mapping->transforms)
        ->toBe([])
        ->and(array_keys($transforms))
        ->toBe(['field_0_0', 'field_0_0_cast'])
        ->and($transforms['field_0_0_cast']['spec'])
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
        ['key' => 'trim', 'params' => []],
        ['key' => 'substring', 'params' => ['start' => '0']],
        ['key' => 'mask', 'params' => ['replacement' => '***']],
    ])?->toArray())->toBe(['type' => 'string']);
    expect($registry->resultSchema(JsonSchema::fromArray(['type' => 'boolean']), [
        ['key' => 'mask', 'params' => ['replacement' => '***']],
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

it('only applies string field transforms to string inputs', function (?ColumnType $type) {
    foreach ([
        Trim::make(),
        Substring::make(),
        MaskField::make(),
    ] as $description) {
        expect($description->accepts(JsonSchema::fromArray($type?->jsonSchema() ?? [])))
            ->toBe($type === ColumnType::String);
    }
})->with([...ColumnType::cases(), null]);

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
                ['key' => 'limit', 'params' => ['length' => '80']],
                ['key' => 'limit', 'params' => ['length' => '40']],
            ])
            ?->toArray(),
    )->toBe(['type' => 'string', 'format' => 'email', 'maxLength' => 40]);
    expect($registry->resultSchema($input, [['key' => 'cast']]))->toBeNull();
});

it('builds editor descriptions without resolving execution handlers', function () {
    app()->bind(CastTransform::class, fn () => throw new LogicException('The editor must not resolve handlers.'));

    expect(app(TransformRegistry::class)->getOrFail('cast')->getSchema(null)[0])->toBeInstanceOf(Select::class);
});

it('builds a configured pipeline without registering editor descriptions', function () {
    config(['phpinnacle-ferry.transforms.headless' => FilterTransform::class]);
    $handler = new FilterTransform;
    app()->bind(FilterTransform::class, fn () => $handler);
    $registry = new TransformRegistry;
    app()->bind(
        TransformRegistry::class,
        fn () => throw new LogicException('The worker must not resolve editor descriptions.'),
    );
    $factory = app(TransformFactory::class);
    $sync = new Sync;
    $sync->forceFill([
        'schema' => [new FieldMapping(
            '_marked',
            null,
            new ScalarField(FieldType::Boolean),
            transforms: [new TransformSetting('headless', [
                'mode' => 'exclude',
                'type' => 'boolean',
                'value' => 'true',
            ])],
        )],
    ]);

    expect($registry->get('headless'))
        ->toBeNull()
        ->and($factory->handler('headless'))
        ->toBe($handler)
        ->and(
            $factory->pipeline($sync, new ConnectionMetadata([
                'system' => ['_marked' => new ScalarField(FieldType::Boolean)],
                'properties' => [],
            ]))['before']['field_0_0']['filter.condition'],
        )
        ->toBe('$[?(@._marked == true)]');
});
