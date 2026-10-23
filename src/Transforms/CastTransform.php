<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;

final class CastTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => sprintf('%s:%s', $field, ColumnType::from($params['type'])->castType()),
                ...(
                    array_key_exists('replace_null_with_default', $params)
                        ? ['replace.null.with.default' => $params['replace_null_with_default']]
                        : []
                ),
            ],
        ];
    }
}
