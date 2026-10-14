<?php

namespace PHPinnacle\Ferry\Transforms;

use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class CastTransform implements Transform
{
    public string $key {
        get => 'cast';
    }

    public TransformScope $scope {
        get => TransformScope::Field;
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::resources.transform.cast');
    }

    public function schema(?ConnectionMetadata $object): array
    {
        return [
            Select::make('type')
                ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                ->options(ColumnType::castableOptions())
                ->required(),
        ];
    }

    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function rules(array $params): array
    {
        return ['type' => ['required', Rule::enum(ColumnType::class)->only(ColumnType::castable())]];
    }

    public function sourceColumns(array $params): array
    {
        return [];
    }

    public function resultType(?ColumnType $input, array $params): ?ColumnType
    {
        $type = ColumnType::from($params['type']);

        return in_array($input, [ColumnType::Integer, ColumnType::Decimal], true) && $type !== ColumnType::String
            ? null
            : $type;
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => sprintf('%s:%s', $field, ColumnType::from($params['type'])->castType()),
            ],
        ];
    }
}
