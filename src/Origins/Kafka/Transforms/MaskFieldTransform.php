<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Transforms;

use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

final class MaskFieldTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
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
