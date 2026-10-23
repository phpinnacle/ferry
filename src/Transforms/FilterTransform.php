<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;

final class FilterTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
        $literal = ColumnType::from($params['type']) === ColumnType::Boolean
            ? $params['value']
            : "'" . addcslashes($params['value'], "\\'") . "'";

        return [
            $name => [
                'type' => 'io.confluent.connect.transforms.Filter$Value',
                'filter.condition' => sprintf('$[?(@.%s == %s)]', $field, $literal),
                'filter.type' => $params['mode'],
                'missing.or.null.behavior' => $params['mode'] === 'include' ? 'exclude' : 'include',
                'predicate' => 'tombstone',
                'negate' => 'true',
            ],
        ];
    }
}
