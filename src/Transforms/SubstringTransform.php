<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class SubstringTransform implements TransformHandler
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
                'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
                'fields' =>
                    $field . ':' . $params['start'] . (array_key_exists('end', $params) ? ':' . $params['end'] : ''),
            ],
        ];
    }
}
