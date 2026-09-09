<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

class SyncSchemaBuilder
{
    /**
     * @param  array<array{source: string, column: string}>  $rows
     * @return list<FieldMapping>
     */
    public function build(ConnectionMetadata $object, array $rows, ?StaticDestination $destination = null): array
    {
        $mappings = [];
        $fields = array_column($destination?->fields() ?? [], null, 'id');

        foreach ($rows as $row) {
            $field = $object->field($row['source']);

            if ($field === null) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unknown_field', [
                        'field' => $row['source'],
                    ]),
                ]);
            }

            if ($destination !== null) {
                $this->assertNotKeyField($object, $row['source']);
                $this->assertCompatibleWithDestination($fields, $row['column'], ColumnType::fromField($field));
            }

            $mappings[] = new FieldMapping($row['source'], $row['column'], $field);
        }

        if ($destination !== null) {
            $this->assertRequiredFieldsMapped($fields, $mappings);
        }

        return $mappings;
    }

    /**
     * @param  array<string, string>  $bindings
     * @return list<FieldMapping>
     */
    public function fromBindings(
        ConnectionMetadata $object,
        array $bindings,
        ?StaticDestination $destination = null,
    ): array {
        $rows = [];

        foreach ($bindings as $source => $column) {
            $rows[] = ['source' => (string) $source, 'column' => $column];
        }

        return $this->build($object, $rows, $destination);
    }

    /** @param array<string, DestinationField> $fields */
    private function assertCompatibleWithDestination(
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

    private function assertNotKeyField(ConnectionMetadata $object, string $source): void
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
    private function assertRequiredFieldsMapped(array $fields, array $mappings): void
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
