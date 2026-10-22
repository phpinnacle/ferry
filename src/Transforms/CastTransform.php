<?php

namespace PHPinnacle\Ferry\Transforms;

use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class CastTransform implements TransformHandler
{
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

    public function resultType(?ColumnType $input, array $params): ColumnType
    {
        return ColumnType::from($params['type']);
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
