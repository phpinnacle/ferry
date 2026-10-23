<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\TransformRegistry;

readonly class SyncSchema
{
    /**
     * @param list<mixed> $columns
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public static function validateColumns(array $columns, Closure $fail): void
    {
        if ($columns === []) {
            $fail('phpinnacle-ferry::validation.sync_schema.empty')->translate();

            return;
        }

        $used = [];
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                $fail('phpinnacle-ferry::validation.sync_schema.column_required')->translate();

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
            $fail('phpinnacle-ferry::validation.sync_schema.column_duplicate')->translate([
                'column' => $column,
            ]);

            return false;
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
            $fail('phpinnacle-ferry::validation.sync_schema.column_format')->translate([
                'column' => $column,
            ]);

            return false;
        }

        if (in_array($column, Sync::RESERVED_COLUMNS, true)) {
            $fail('phpinnacle-ferry::validation.sync_schema.column_reserved')->translate([
                'column' => $column,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, string|null> $bindings
     * @param array{fields?: array<string, list<TransformSetting>>, constants?: array<string, mixed>} $transforms
     * @return list<FieldMapping>
     */
    public static function fromBindings(
        ConnectionMetadata $object,
        array $bindings,
        TransformRegistry $registry,
        ?StaticDestination $destination = null,
        array $transforms = [],
    ): array {
        $mappings = [];
        $fields = array_column($destination->fields ?? [], null, 'id');

        $sources = $bindings + array_fill_keys(array_keys($transforms['fields'] ?? []), null);
        foreach ($sources as $source => $column) {
            $source = (string) $source;
            $field = $object->field($source);

            if ($field === null) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unknown_field', [
                        'field' => $source,
                    ]),
                ]);
            }

            $settings = $transforms['fields'][$source] ?? [];
            $type = ColumnType::fromField($field);
            $schema = JsonSchema::fromArray($type?->jsonSchema() ?? []);

            foreach ($settings as $setting) {
                $transform = $registry->getOrFail($setting->key);
                $schema = $transform->accepts($schema) ? $transform->getOutputSchema($schema, $setting->params) : null;

                if ($schema === null) {
                    throw ValidationException::withMessages([
                        'data.transforms' => __('phpinnacle-ferry::resources.sync.errors.transform_field_unsupported', [
                            'transform' => $transform->getLabel(),
                            'field' => $source,
                        ]),
                    ]);
                }
            }

            if ($settings !== [] && $column !== null) {
                $type = ColumnType::fromJsonSchema($schema);
                if ($type === null) {
                    throw ValidationException::withMessages([
                        'data.transforms' => __('phpinnacle-ferry::resources.sync.errors.transform_field_unsupported', [
                            'transform' => $registry->getOrFail($settings[array_key_last($settings)]->key)->getLabel(),
                            'field' => $source,
                        ]),
                    ]);
                }
            }

            if ($destination !== null && $column !== null) {
                self::assertNotKeyField($object, $source);
                self::assertCompatibleWithDestination($fields, $column, $type);
            }

            $mappings[] = new FieldMapping(
                $source,
                $column,
                $field,
                $settings === [] ? null : ColumnType::fromJsonSchema($schema),
                $settings,
            );
        }

        foreach ($transforms['constants'] ?? [] as $column => $value) {
            $column = (string) $column;
            Validator::make(['static' => $value], [
                'static' => ['required', self::validateStaticValue(...)],
            ])->validate();
            /** @var string|int|float|bool $value */
            $mapping = new FieldMapping(null, $column, null, static: $value, kind: FieldKind::Static);

            if (in_array($column, array_column($mappings, 'target'), true)) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.constant_column_taken', [
                        'column' => $column,
                    ]),
                ]);
            }

            if ($destination !== null) {
                self::assertCompatibleWithDestination($fields, $column, $mapping->type());
            }

            $mappings[] = $mapping;
        }

        foreach ($object->mappableFields(withKey: $destination === null) as $entry) {
            if (!array_key_exists($entry['source'], $sources)) {
                $mappings[] = new FieldMapping($entry['source'], null, $object->field($entry['source']));
            }
        }

        if ($destination !== null) {
            self::assertRequiredFieldsMapped($fields, $mappings);
        }

        return $mappings;
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private static function validateStaticValue(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            $fail('The :attribute field must be a scalar value.')->translate();
        }
    }

    /** @param list<FieldMapping> $mappings */
    public static function assertValid(array $mappings, ?StaticDestination $destination): void
    {
        $fields = array_column($destination->fields ?? [], null, 'id');
        foreach ($mappings as $mapping) {
            if ($mapping->target === null) {
                continue;
            }
            $type = $mapping->type();
            if ($type === null) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.unsupported_field_type', [
                        'column' => $mapping->target,
                    ]),
                ]);
            }
            if ($destination !== null) {
                self::assertCompatibleWithDestination($fields, $mapping->target, $type);
            }
        }
        if ($destination !== null) {
            self::assertRequiredFieldsMapped($fields, $mappings);
        }
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
        $mapped = array_map(static fn (FieldMapping $mapping) => $mapping->target, $mappings);

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
