<?php

namespace PHPinnacle\Ferry\Origins\Kafka\Transforms;

use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

final class SubstringTransform implements TransformHandler
{
    public function connector(string $name, string $field, array $params): array
    {
        return [
            $name => [
                'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
                'fields' =>
                    $field . ':' . $params['start'] . (array_key_exists('end', $params) ? ':' . $params['end'] : ''),
            ],
        ];
    }
}
