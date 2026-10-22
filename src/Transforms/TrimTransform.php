<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class TrimTransform implements TransformHandler
{
    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function rules(array $params): array
    {
        return [];
    }

    public function sourceColumns(array $params): array
    {
        return [];
    }

    public function resultType(?ColumnType $input, array $params): ?ColumnType
    {
        return $input === ColumnType::String ? $input : null;
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        /** @var string $field */
        return [
            $name => [
                'type' => 'org.phpinnacle.toblerone.TrimTransform$Value',
                'fields' => $field,
            ],
        ];
    }
}
