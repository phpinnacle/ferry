<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\StaticValue;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Syncs\Schemas\TransformsBuilder;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\Cast;
use PHPinnacle\Ferry\Transformations\Filter;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Ferry\Transformations\Substring;
use PHPinnacle\Ferry\Transformations\Trim;
use PHPinnacle\Ferry\Transforms\CastTransform;
use PHPinnacle\Ferry\Transforms\FilterTransform;
use PHPinnacle\Ferry\Transforms\MaskFieldTransform;
use PHPinnacle\Ferry\Transforms\SubstringTransform;
use PHPinnacle\Ferry\Transforms\TrimTransform;
use Tests\TestCase;

require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    FerryPlugin::make();
});

it('registers the built-in transforms through the same factory as application transforms', function () {
    $factory = $this->app->make(TransformFactory::class);

    expect($factory->get('cast'))
        ->toBeInstanceOf(Transformation::class)
        ->and($factory->handler('cast'))
        ->toBeInstanceOf(CastTransform::class)
        ->and($factory->get('static_value'))
        ->toBeNull()
        ->and($factory->get('unknown'))
        ->toBeNull();
});

it('registers application transforms through the plugin', function () {
    FerryPlugin::make()->transforms(Transformation::make('trim', TransformScope::Record)->label('Trim'));

    $factory = $this->app->make(TransformFactory::class);

    expect($factory->get('trim'))
        ->not
        ->toBeNull()
        ->and(array_keys($factory->forScope(TransformScope::Record)))
        ->toBe(['filter', 'trim'])
        ->and(array_keys($factory->forScope(TransformScope::Field)))
        ->toBe(['cast']);
});

it('casts a field to the selected column type', function (ColumnType $type, string $spec) {
    $cast = new CastTransform;

    expect($cast->resultType(ColumnType::String, ['type' => $type->value]))
        ->toBe($type)
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

    expect(app(TransformFactory::class)->resultType(ColumnType::String, [
        ['key' => 'cast', 'params' => ['type' => $intermediate]],
        ['key' => 'cast', 'params' => ['type' => $result->value]],
    ]))
        ->toBe($result);
})->with([
    'integer to boolean' => ['integer', ColumnType::Boolean],
    'decimal to integer' => ['decimal', ColumnType::Integer],
    'integer to decimal' => ['integer', ColumnType::Decimal],
    'integer to integer' => ['integer', ColumnType::Integer],
]);

it('inserts a string constant without registering a transform', function () {
    $sync = new Sync([
        'schema' => [
            new FieldMapping(null, 'type', null, constant: new StaticValue(ColumnType::String, 'Customer')),
        ],
        'transforms' => [],
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
    $constant = new StaticValue(ColumnType::Boolean, 'true');
    $mapping = new FieldMapping(null, 'is_active', null, constant: $constant);
    $sync = new Sync(['schema' => [$mapping], 'transforms' => []]);
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
    $factory = $this->app->make(TransformFactory::class);
    expect($factory->get('substring'))
        ->toBeNull()
        ->and($factory->get('mask'))
        ->toBeNull()
        ->and($factory->get('trim'))
        ->toBeNull();

    FerryPlugin::make()->transforms(Trim::make(), Substring::make(), MaskField::make());

    expect(array_keys($factory->forScope(TransformScope::Field)))->toBe(['cast', 'trim', 'substring', 'mask']);
    expect($factory->resultTypes(['title' => 'string', 'active' => 'boolean'], [
        'title' => [
            ['key' => 'trim', 'params' => []],
            ['key' => 'substring', 'params' => ['start' => '0']],
            ['key' => 'mask', 'params' => ['replacement' => '***']],
        ],
        'active' => [['key' => 'mask', 'params' => ['replacement' => '***']]],
    ]))->toBe(['title' => 'string']);
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

it('preserves transform settings and order when builder blocks are filled reordered and removed', function () {
    $factory = app(TransformFactory::class);
    $factory->register(Trim::make(), MaskField::make());
    $livewire = SchemaHost::make();
    $builder = TransformsBuilder::make(TransformScope::Field, null, $factory);
    $schema = Schema::make($livewire)->statePath('data')->components([$builder]);
    $settings = [
        ['key' => 'trim', 'params' => []],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
    ];
    $schema->fill(['transforms' => $settings]);

    expect($schema->getState()['transforms'])->toBe($settings);

    $keys = array_keys($builder->getRawState());
    $builder
        ->getAction('reorder')
        ->arguments(['items' => array_reverse($keys)])
        ->call();

    expect($schema->getState()['transforms'])->toBe(array_reverse($settings));

    $builder->getAction('delete')->arguments(['item' => $keys[1]])->call();

    expect($schema->getState()['transforms'])->toBe([$settings[0]]);
});

it('renders transform icons and localized descriptions without adding them to submitted settings', function (string $locale) {
    app()->setLocale($locale);
    $factory = app(TransformFactory::class);
    $factory->register(Trim::make(), Substring::make(), MaskField::make());
    $livewire = SchemaHost::make();
    $builder = TransformsBuilder::make(TransformScope::Field, null, $factory);
    $schema = Schema::make($livewire)->statePath('data')->components([$builder]);
    $settings = [
        ['key' => 'trim', 'params' => []],
        ['key' => 'substring', 'params' => ['start' => '1', 'end' => '5']],
        ['key' => 'mask', 'params' => ['replacement' => 'hidden']],
        ['key' => 'cast', 'params' => ['type' => 'string']],
    ];
    $schema->fill(['transforms' => $settings]);
    $html = $schema->toHtml();

    foreach ($factory->forScope(TransformScope::Field) as $transform) {
        if ($transform->getDescription() !== null) {
            expect($html)->toContain(e($transform->getDescription()));
        }
    }

    expect($schema->getState()['transforms'])->toBe($settings);

    $filter = Filter::make();
    $builder = TransformsBuilder::make($filter->scope(), null, $factory);
    $schema = Schema::make($livewire)->statePath('data')->components([$builder]);
    $schema->fill(['transforms' => [['key' => $filter->key(), 'params' => []]]]);

    expect($schema->toHtml())
        ->toContain(e($filter->getDescription()));
})->with(['en', 'ru']);

it('only applies string field transforms to string inputs', function (?ColumnType $type) {
    foreach ([
        [Trim::make(), new TrimTransform],
        [Substring::make(), new SubstringTransform],
        [MaskField::make(), new MaskFieldTransform],
    ] as [$description, $transform]) {
        expect($description->accepts($type))->toBe($type === ColumnType::String);
        expect($transform->resultType($type, []))->toBe($type === ColumnType::String ? ColumnType::String : null);
    }
})->with([...ColumnType::cases(), null]);

it('validates installed field transform settings at the domain boundary', function (
    TransformHandler $transform,
    array $params,
    bool $valid,
) {
    expect(Validator::make($params, $transform->rules($params))->passes())->toBe($valid);
})->with([
    'start only' => [new SubstringTransform, ['start' => '0'], true],
    'equal indexes' => [new SubstringTransform, ['start' => '2', 'end' => '2'], true],
    'missing start' => [new SubstringTransform, [], false],
    'negative start' => [new SubstringTransform, ['start' => '-1'], false],
    'fractional start' => [new SubstringTransform, ['start' => '1.5'], false],
    'non-scalar start' => [new SubstringTransform, ['start' => []], false],
    'index exceeds Java integer' => [new SubstringTransform, ['start' => '2147483648'], false],
    'reversed bounds' => [new SubstringTransform, ['start' => '3', 'end' => '2'], false],
    'empty end is not a stored index' => [new SubstringTransform, ['start' => '0', 'end' => ''], false],
    'custom mask' => [new MaskFieldTransform, ['replacement' => '***'], true],
    'missing mask' => [new MaskFieldTransform, [], false],
    'empty mask' => [new MaskFieldTransform, ['replacement' => ''], false],
    'non-scalar mask' => [new MaskFieldTransform, ['replacement' => []], false],
]);

it('dehydrates native settings forms into string parameters and omits an empty end', function (
    Transformation $transform,
    array $params,
    ?array $expected,
) {
    $livewire = SchemaHost::make();
    $schema = Schema::make($livewire)->statePath('data')->components($transform->getSchema(null));
    $schema->fill($params === [] ? null : $params);

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
]);

it('keeps description values immutable and returns fresh form components', function () {
    $base = Transformation::make('custom', TransformScope::Field);
    $definition = $base
        ->label('Custom transform')
        ->description('Custom description')
        ->icon(Heroicon::Scissors)
        ->color(fn () => 'success')
        ->inputTypes([ColumnType::String])
        ->outputType(ColumnType::Boolean)
        ->schema([TextInput::make('value')]);

    expect($base->getLabel())
        ->toBe('custom')
        ->and($base->getDescription())
        ->toBeNull()
        ->and($base->getColor())
        ->toBe('primary')
        ->and($base->getSchema(null))
        ->toBe([])
        ->and($base->accepts(ColumnType::Boolean))
        ->toBeTrue()
        ->and($base->getOutputType(ColumnType::String, []))
        ->toBe(ColumnType::String)
        ->and($definition->accepts(ColumnType::Boolean))
        ->toBeFalse()
        ->and($definition->accepts(ColumnType::String))
        ->toBeTrue()
        ->and($definition->getOutputType(ColumnType::String, []))
        ->toBe(ColumnType::Boolean)
        ->and($definition->getLabel())
        ->toBe('Custom transform')
        ->and($definition->getDescription())
        ->toBe('Custom description')
        ->and($definition)
        ->toBeInstanceOf(HasColor::class)
        ->and($definition->getColor())
        ->toBe('success')
        ->and($definition->getIcon())
        ->toBe(Heroicon::Scissors)
        ->and($definition->getSchema(null)[0])
        ->not->toBe($definition->getSchema(null)[0]);
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

it('builds editor descriptions without resolving execution handlers', function () {
    app()->bind(CastTransform::class, fn () => throw new LogicException('The editor must not resolve handlers.'));

    expect(app(TransformFactory::class)->getOrFail('cast')->getSchema(null)[0])->toBeInstanceOf(Select::class);
});

it('previews cast types from descriptions consistently with execution', function (?ColumnType $input) {
    $description = Cast::make();
    foreach (ColumnType::castable() as $target) {
        $params = ['type' => $target->value];
        expect($description->getOutputType($input, $params))->toBe(new CastTransform()->resultType($input, $params));
    }
})->with([...ColumnType::cases(), null]);

it('builds a configured pipeline without registering editor descriptions', function () {
    config(['phpinnacle-ferry.transforms.headless' => FilterTransform::class]);
    $handler = new FilterTransform;
    app()->bind(FilterTransform::class, fn () => $handler);
    $factory = new TransformFactory;
    $sync = new Sync;
    $sync->forceFill([
        'transforms' => [new TransformSetting('headless', [
            'mode' => 'exclude',
            'column' => '_marked',
            'type' => 'boolean',
            'value' => 'true',
        ])],
        'schema' => [],
    ]);

    expect($factory->get('headless'))
        ->toBeNull()
        ->and($factory->handler('headless'))
        ->toBe($handler)
        ->and($factory->pipeline($sync, new ConnectionMetadata)['before']['record_0']['filter.condition'])
        ->toBe('$[?(@._marked == true)]');
});
