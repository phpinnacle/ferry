<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use PHPinnacle\Ferry\Services\SyncTableManager;

readonly class SyncSchema implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('phpinnacle-ferry::validation.sync_schema.format')->translate();

            return;
        }

        if ($value === []) {
            $fail('phpinnacle-ferry::validation.sync_schema.empty')->translate();

            return;
        }

        $sources = [];
        $columns = [];

        foreach ($value as $row) {
            $source = is_array($row) ? $row['source'] ?? null : null;
            $column = is_array($row) ? $row['column'] ?? null : null;

            if (!is_string($source) || $source === '') {
                $fail('phpinnacle-ferry::validation.sync_schema.source_required')->translate();

                return;
            }

            if (!is_string($column) || $column === '') {
                $fail('phpinnacle-ferry::validation.sync_schema.column_required')->translate();

                return;
            }

            if (array_key_exists($source, $sources)) {
                $fail('phpinnacle-ferry::validation.sync_schema.source_duplicate')->translate([
                    'field' => $source,
                ]);

                return;
            }

            if (array_key_exists($column, $columns)) {
                $fail('phpinnacle-ferry::validation.sync_schema.column_duplicate')->translate([
                    'column' => $column,
                ]);

                return;
            }

            if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                $fail('phpinnacle-ferry::validation.sync_schema.column_format')->translate([
                    'column' => $column,
                ]);

                return;
            }

            if (in_array($column, SyncTableManager::RESERVED_COLUMNS, true)) {
                $fail('phpinnacle-ferry::validation.sync_schema.column_reserved')->translate([
                    'column' => $column,
                ]);

                return;
            }

            $sources[$source] = true;
            $columns[$column] = true;
        }
    }
}
