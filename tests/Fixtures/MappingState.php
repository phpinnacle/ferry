<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use PHPinnacle\Eidos\Forms\FieldMapping;

/**
 * @phpstan-import-type SourceEntry from FieldMapping
 * @phpstan-import-type ValueEntry from FieldMapping
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

    public static function mapping(array $targets, array $sources = [], array $available = []): array
    {
        $sources += array_fill_keys($available, []);
        $rows = [];
        $used = [];
        foreach ($targets as $target => $mapped) {
            foreach (array_is_list($mapped) ? $mapped : [$mapped] as $entry) {
                if ($entry['kind'] === 'value') {
                    $rows[] = [
                        'kind' => 'static',
                        'target' => (string) $target,
                        'static' => $entry['value'],
                        'options' => [],
                    ];
                } else {
                    $rows[] = ['kind' => 'source', 'source' => $entry['source'], 'target' => (string) $target]
                    + ['options' => ['transforms' => [], ...($sources[$entry['source']] ?? [])]];
                    $used[$entry['source']] = true;
                }
            }
        }
        foreach ($sources as $source => $settings) {
            if (!array_key_exists($source, $used)) {
                $rows[] = ['kind' => 'source', 'source' => (string) $source, 'target' => null]
                + ['options' => ['transforms' => [], ...$settings]];
            }
        }

        return $rows;
    }

    public static function binding(array $bindings, array $sources = [], array $available = []): array
    {
        $available = $available === [] ? array_keys($bindings + $sources) : $available;
        $rows = [];
        foreach ($available as $source) {
            $value = $bindings[$source] ?? null;
            if (is_array($value) && array_key_exists('value', $value)) {
                continue;
            }
            $rows[] = ['kind' => 'source', 'source' => (string) $source, 'target' => $value]
            + ['options' => ['transforms' => [], ...($sources[$source] ?? [])]];
        }
        foreach ($bindings as $target => $value) {
            if (is_array($value) && array_key_exists('value', $value)) {
                $rows[] = [
                    'kind' => 'static',
                    'target' => (string) $target,
                    'static' => $value['value'],
                    'options' => [],
                ];
            }
        }

        return $rows;
    }
}
