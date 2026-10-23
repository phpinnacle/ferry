<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;

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
