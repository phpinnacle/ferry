<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use PHPinnacle\Ferry\Forms\FieldMapping;

/**
 * @phpstan-import-type SourceEntry from FieldMapping
 * @phpstan-import-type ValueEntry from FieldMapping
 * @phpstan-import-type Targets from FieldMapping
 * @phpstan-import-type State from FieldMapping
 */
final class MappingState
{
    /** @return SourceEntry */
    public static function source(string $source): array
    {
        return ['kind' => 'source', 'source' => $source];
    }

    /** @return ValueEntry */
    public static function value(mixed $value): array
    {
        return ['kind' => 'value', 'value' => $value];
    }

    /**
     * @param Targets $targets
     * @param array<string, array<string, mixed>> $sources
     * @return State
     */
    public static function mapping(array $targets, array $sources = []): array
    {
        return ['sources' => $sources, 'targets' => $targets];
    }

    /**
     * @param array<string, mixed> $bindings
     * @param array<string, array<string, mixed>> $sources
     * @return array{sources: array<string, array<string, mixed>>, bindings: array<string, mixed>}
     */
    public static function binding(array $bindings, array $sources = []): array
    {
        return ['sources' => $sources, 'bindings' => $bindings];
    }

    public static function encode(FieldMapping $field, mixed $targets, array $sources = []): mixed
    {
        if (!is_array($targets)) {
            return $targets;
        }

        $keys = $field->getStateKeys();
        $sourceKeys = $field->getSourceStateKeys();

        return [
            'sources' => array_combine(
                array_map(fn ($id) => $sourceKeys[$id] ?? $id, array_keys($sources)),
                array_values($sources),
            ),
            'targets' => array_combine(
                array_map(fn ($id) => $keys[$id] ?? $id, array_keys($targets)),
                array_values($targets),
            ),
        ];
    }
}
