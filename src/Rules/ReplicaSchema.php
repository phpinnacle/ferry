<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Services\ReplicaTableManager;

readonly class ReplicaSchema
{
    /**
     * @param list<mixed> $columns
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public static function validateColumns(array $columns, Closure $fail): void
    {
        if ($columns === []) {
            $fail('phpinnacle-ferry::validation.replica_schema.empty')->translate();

            return;
        }

        $used = [];
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                $fail('phpinnacle-ferry::validation.replica_schema.column_required')->translate();

                return;
            }
            if (!self::validateColumn($column, $used, $fail)) {
                return;
            }
            $used[$column] = true;
        }
    }

    /**
     * @param array<string, true> $used
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    private static function validateColumn(string $column, array $used, Closure $fail): bool
    {
        if (array_key_exists($column, $used)) {
            $fail('phpinnacle-ferry::validation.replica_schema.column_duplicate')->translate([
                'column' => $column,
            ]);

            return false;
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
            $fail('phpinnacle-ferry::validation.replica_schema.column_format')->translate([
                'column' => $column,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $primaryKey
     * @param list<string> $sources
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public static function validatePrimaryKey(array $primaryKey, array $sources, Closure $fail): void
    {
        if ($primaryKey === []) {
            $fail('phpinnacle-ferry::validation.replica_schema.primary_key_missing')->translate();

            return;
        }
        $missing = array_diff($primaryKey, $sources);
        if ($missing !== []) {
            $fail('phpinnacle-ferry::validation.replica_schema.primary_key_required')->translate([
                'columns' => implode(', ', $missing),
            ]);
        }
    }

    /** @param list<FieldMapping> $mappings */
    public static function assertValid(array $mappings, ?StaticDestination $destination): void
    {
        $destinationSchema = JsonSchema::fromArray($destination?->getSchema()->keywords() ?? []);
        $fields = $destinationSchema->properties();
        foreach ($mappings as $mapping) {
            if ($mapping->target === null) {
                continue;
            }
            if (ReplicaTableManager::columnType($mapping->outputSchema()) === null) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.replica.errors.unsupported_field_type', [
                        'column' => $mapping->target,
                    ]),
                ]);
            }
            if ($destination !== null) {
                self::assertCompatibleWithDestination($fields, $mapping->target, $mapping);
            }
        }
        if ($destination !== null) {
            self::assertRequiredFieldsMapped($destinationSchema, $mappings);
        }
    }

    /** @param array<string, JsonSchema> $fields */
    private static function assertCompatibleWithDestination(
        array $fields,
        string $column,
        FieldMapping $mapping,
    ): void {
        $field = $fields[$column] ?? null;

        if ($field === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.replica.errors.unknown_destination_field', [
                    'field' => $column,
                ]),
            ]);
        }

        if (!$mapping->canMapTo($field)) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.replica.errors.incompatible_destination_field', [
                    'field' => $field->annotation('title') ?? $column,
                ]),
            ]);
        }
    }

    /** @param list<FieldMapping> $mappings */
    private static function assertRequiredFieldsMapped(JsonSchema $schema, array $mappings): void
    {
        $mapped = array_map(static fn (FieldMapping $mapping) => $mapping->target, $mappings);
        $fields = $schema->properties();

        foreach ($schema->required() as $column) {
            if (!in_array($column, $mapped, true)) {
                throw ValidationException::withMessages([
                    'data.schema' => __(
                        'phpinnacle-ferry::resources.replica.errors.required_destination_field_missing',
                        ['field' => $fields[$column]->annotation('title') ?? $column],
                    ),
                ]);
            }
        }
    }
}
