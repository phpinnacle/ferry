<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;

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

            if (in_array($column, Sync::RESERVED_COLUMNS, true)) {
                $fail('phpinnacle-ferry::validation.sync_schema.column_reserved')->translate([
                    'column' => $column,
                ]);

                return;
            }

            $sources[$source] = true;
            $columns[$column] = true;
        }
    }

    /**
     * @param array<string, string> $bindings
     * @return list<FieldMapping>
     */
    public static function fromBindings(
        ConnectionMetadata $object,
        array $bindings,
        ?StaticDestination $destination = null,
    ): array {
        $mappings = [];
        $fields = array_column($destination->fields ?? [], null, 'id');

        foreach ($bindings as $source => $column) {
            $source = (string) $source;
            $field = $object->field($source);

            if ($field === null) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unknown_field', [
                        'field' => $source,
                    ]),
                ]);
            }

            if ($destination !== null) {
                self::assertNotKeyField($object, $source);
                self::assertCompatibleWithDestination($fields, $column, ColumnType::fromField($field));
            }

            $mappings[] = new FieldMapping($source, $column, $field);
        }

        if ($destination !== null) {
            self::assertRequiredFieldsMapped($fields, $mappings);
        }

        return $mappings;
    }

    /** @param array<string, DestinationField> $fields */
    private static function assertCompatibleWithDestination(
        array $fields,
        string $column,
        ?ColumnType $type,
    ): void {
        $field = $fields[$column] ?? null;

        if ($field === null) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unknown_destination_field', [
                    'field' => $column,
                ]),
            ]);
        }

        if ($field->type !== $type) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.incompatible_destination_field', [
                    'field' => $field->label,
                ]),
            ]);
        }
    }

    private static function assertNotKeyField(ConnectionMetadata $object, string $source): void
    {
        if ($object->physicalColumn($source) === ConnectionMetadata::KEY_COLUMN) {
            throw ValidationException::withMessages([
                'data.schema' => __('phpinnacle-ferry::resources.sync.errors.key_field_not_mappable', [
                    'field' => $source,
                ]),
            ]);
        }
    }

    /**
     * @param array<string, DestinationField> $fields
     * @param list<FieldMapping> $mappings
     */
    private static function assertRequiredFieldsMapped(array $fields, array $mappings): void
    {
        $mapped = array_map(static fn (FieldMapping $mapping) => $mapping->column, $mappings);

        foreach ($fields as $field) {
            if ($field->required && !in_array($field->id, $mapped, true)) {
                throw ValidationException::withMessages([
                    'data.schema' => __(
                        'phpinnacle-ferry::resources.sync.errors.required_destination_field_missing',
                        ['field' => $field->label],
                    ),
                ]);
            }
        }
    }
}
