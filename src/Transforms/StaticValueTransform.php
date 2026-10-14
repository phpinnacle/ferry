<?php

namespace PHPinnacle\Ferry\Transforms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class StaticValueTransform implements Transform
{
    public const string KEY = 'static_value';

    public string $key {
        get => self::KEY;
    }

    public TransformScope $scope {
        get => TransformScope::Static;
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::resources.transform.static_value');
    }

    public function schema(?ConnectionMetadata $object): array
    {
        return [
            Select::make('type')
                ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                ->options(ColumnType::castableOptions())
                ->required(),
            TextInput::make('value')
                ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                ->required(),
        ];
    }

    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function rules(array $params): array
    {
        return [
            'type' => ['required', Rule::enum(ColumnType::class)->only(ColumnType::castable())],
            'value' => [
                'required',
                'string',
                (ColumnType::tryFrom($params['type'] ?? '') ?? ColumnType::String)->valueRule(),
            ],
        ];
    }

    public function sourceColumns(array $params): array
    {
        return [];
    }

    public function resultType(?ColumnType $input, array $params): ColumnType
    {
        return ColumnType::from($params['type']);
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        /** @var string $field */
        $type = ColumnType::from($params['type']);
        $transforms = [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $field,
                'static.value' => $params['value'],
            ],
        ];

        if ($type !== ColumnType::String) {
            $transforms[$name . '_cast'] = [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => sprintf('%s:%s', $field, $type->castType()),
            ];
        }

        return $transforms;
    }
}
