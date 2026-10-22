<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class MaskFieldTransform implements TransformHandler
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
        /** @var string $field */
        return [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
                'fields' => $field,
                'replacement' => $params['replacement'],
                'predicate' => 'tombstone',
                'negate' => 'true',
            ],
        ];
    }
}
