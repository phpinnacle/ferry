<?php

namespace PHPinnacle\Ferry\Transforms;

use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final class FilterTransform implements TransformHandler
{
    private const array MODES = ['include', 'exclude'];

    public function normalize(array $params, ConnectionMetadata $object): array
    {
        $field =
            array_column($object->mappableFields(withKey: false), null, 'physical')[$params['column'] ?? ''] ?? null;

        return $field === null ? $params : [...$params, 'type' => $field['type']->value];
    }

    public function rules(array $params): array
    {
        return [
            'mode' => ['required', Rule::in(self::MODES)],
            'column' => ['required', 'string', 'regex:/^\w+$/'],
            'type' => ['required', Rule::enum(ColumnType::class)->only(ColumnType::filterable())],
            'value' => [
                'required',
                'string',
                (ColumnType::tryFrom($params['type'] ?? '') ?? ColumnType::String)->valueRule(),
            ],
        ];
    }

    public function sourceColumns(array $params): array
    {
        return [$params['column']];
    }

    public function resultType(?ColumnType $input, array $params): ?ColumnType
    {
        return $input;
    }

    public function connector(string $name, ?string $field, array $params): array
    {
        $literal = ColumnType::from($params['type']) === ColumnType::Boolean
            ? $params['value']
            : "'" . addcslashes($params['value'], "\\'") . "'";

        return [
            $name => [
                'type' => 'io.confluent.connect.transforms.Filter$Value',
                'filter.condition' => sprintf('$[?(@.%s == %s)]', $params['column'], $literal),
                'filter.type' => $params['mode'],
                'missing.or.null.behavior' => $params['mode'] === 'include' ? 'exclude' : 'include',
                'predicate' => 'tombstone',
                'negate' => 'true',
            ],
        ];
    }
}
