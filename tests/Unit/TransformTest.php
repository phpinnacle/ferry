<?php

use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Transforms\CastTransform;
use PHPinnacle\Ferry\Transforms\StaticValueTransform;
use Tests\TestCase;

uses(TestCase::class);

function trim_transform(): Transform
{
    return new class implements Transform {
        public string $key {
            get => 'trim';
        }

        public TransformScope $scope {
            get => TransformScope::Record;
        }

        public function getLabel(): string
        {
            return 'Trim';
        }

        public function schema(?ConnectionMetadata $object): array
        {
            return [];
        }

        public function normalize(array $params, ConnectionMetadata $object): array
        {
            return $params;
        }

        public function rules(array $params): array
        {
            return [];
        }

        public function sourceColumns(array $params): array
        {
            return [];
        }

        public function resultType(?ColumnType $input, array $params): ?ColumnType
        {
            return $input;
        }

        public function connector(string $name, ?string $field, array $params): array
        {
            return [];
        }
    };
}

it('registers the built-in transforms through the same factory as application transforms', function () {
    $factory = $this->app->make(TransformFactory::class);

    expect($factory->get('cast'))
        ->toBeInstanceOf(CastTransform::class)
        ->and($factory->get('static_value'))
        ->toBeInstanceOf(StaticValueTransform::class)
        ->and($factory->get('unknown'))
        ->toBeNull();
});

it('registers application transforms through the plugin', function () {
    FerryPlugin::make()->transforms(trim_transform());

    $factory = $this->app->make(TransformFactory::class);

    expect($factory->get('trim'))
        ->not
        ->toBeNull()
        ->and(array_keys($factory->forScope(TransformScope::Record)))
        ->toBe(['filter', 'trim'])
        ->and(array_keys($factory->forScope(TransformScope::Field)))
        ->toBe(['cast'])
        ->and(array_keys($factory->forScope(TransformScope::Static)))
        ->toBe(['static_value']);
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

it('inserts a string constant without a cast', function () {
    $transforms = new StaticValueTransform()->connector('fill_type', 'type', [
        'type' => 'string',
        'value' => 'Customer',
    ]);

    expect($transforms)->toBe([
        'fill_type' => [
            'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
            'static.field' => 'type',
            'static.value' => 'Customer',
        ],
    ]);
});

it('casts a typed constant after inserting it', function () {
    $static = new StaticValueTransform;
    $transforms = $static->connector('fill_active', 'is_active', ['type' => 'boolean', 'value' => 'true']);

    expect($static->resultType(null, ['type' => 'boolean', 'value' => 'true']))
        ->toBe(ColumnType::Boolean)
        ->and(array_keys($transforms))
        ->toBe(['fill_active', 'fill_active_cast'])
        ->and($transforms['fill_active_cast']['spec'])
        ->toBe('is_active:boolean');
});
