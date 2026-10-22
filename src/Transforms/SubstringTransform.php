<?php

namespace PHPinnacle\Ferry\Transforms;

use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class SubstringTransform implements TransformHandler
{
    public function normalize(array $params, ConnectionMetadata $object): array
    {
        return $params;
    }

    public function rules(array $params): array
    {
        return [
            'start' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'end' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647', 'gte:start'],
        ];
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
        return [
            $name => [
                'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
                'fields' =>
                    $field . ':' . $params['start'] . (array_key_exists('end', $params) ? ':' . $params['end'] : ''),
            ],
        ];
    }
}
