<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class CastTransform implements TransformHandler
{
    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function sourceColumns(array $params): array
    {
        return [];
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
