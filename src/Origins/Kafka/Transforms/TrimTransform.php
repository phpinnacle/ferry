<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Transforms;

use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

final class TrimTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'org.phpinnacle.toblerone.TrimTransform$Value',
                'fields' => $field,
            ],
        ];
    }
}
