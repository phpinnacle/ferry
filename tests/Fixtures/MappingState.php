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
        foreach ($targets as $target => $mapped) {
            $collection = array_is_list($mapped);
            $entries = array_map(
                fn (array $entry) => $entry['kind'] === 'source' ? $entry + ($sources[$entry['source']] ?? []) : $entry,
                $collection ? $mapped : [$mapped],
            );
            $targets[$target] = $collection ? $entries : $entries[0];
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $bindings
     * @param array<string, array<string, mixed>> $sources
     * @return array<string, array<string, mixed>>
     */
    public static function binding(array $bindings, array $sources = []): array
    {
        foreach ($bindings as $source => $value) {
            $constant = is_array($value) && array_key_exists('value', $value);
            $bindings[$source] = $constant
                ? ['kind' => 'value', 'value' => $value]
                : ['kind' => 'source', 'source' => (string) $source, 'value' => $value] + ($sources[$source] ?? []);
        }

        return $bindings;
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
