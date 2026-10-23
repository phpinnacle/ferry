<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;

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
