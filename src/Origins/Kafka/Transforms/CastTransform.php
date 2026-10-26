<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Transforms;

use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

final class CastTransform implements TransformHandler
{
    public static function type(string $type): string
    {
        return [
            'string' => 'string',
            'integer' => 'int64',
            'number' => 'float64',
            'boolean' => 'boolean',
        ][$type];
    }

    public function connector(string $name, string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => sprintf('%s:%s', $field, self::type($params['type'])),
                ...(
                    array_key_exists('replace_null_with_default', $params)
                        ? ['replace.null.with.default' => $params['replace_null_with_default']]
                        : []
                ),
            ],
        ];
    }
}
