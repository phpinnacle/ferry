<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Transforms\StaticValueTransform;

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
     * @param array{fields?: array<string, list<TransformSetting>>, constants?: array<string, array<string, string>>} $transforms
     * @return list<FieldMapping>
     */
    public static function fromBindings(
        ConnectionMetadata $object,
        array $bindings,
        TransformFactory $registry,
        ?StaticDestination $destination = null,
        array $transforms = [],
    ): array {
        $mappings = [];
        $fields = array_column($destination->fields ?? [], null, 'id');

        foreach (array_keys($transforms['fields'] ?? []) as $source) {
            if (!array_key_exists($source, $bindings)) {
                throw ValidationException::withMessages([
                    'data.transforms' => __('phpinnacle-ferry::resources.sync.errors.transform_source_unmapped', [
                        'field' => $source,
                    ]),
                ]);
            }
        }

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

            $settings = $transforms['fields'][$source] ?? [];
            $type = ColumnType::fromField($field);

            foreach ($settings as $setting) {
                $transform = self::transform($setting, TransformScope::Field, $registry);
                $type = $transform->resultType($type, $setting->params);

                if ($type === null) {
                    throw ValidationException::withMessages([
                        'data.transforms' => __('phpinnacle-ferry::resources.sync.errors.transform_field_unsupported', [
                            'transform' => $transform->getLabel(),
                            'field' => $source,
                        ]),
                    ]);
                }
            }

            if ($destination !== null) {
                self::assertNotKeyField($object, $source);
                self::assertCompatibleWithDestination($fields, $column, $type);
            }

            $mappings[] = new FieldMapping($source, $column, $field, $settings === [] ? null : $type, $settings);
        }

        foreach ($transforms['constants'] ?? [] as $column => $params) {
            $column = (string) $column;
            $setting = new TransformSetting(StaticValueTransform::KEY, $params);
            $type = self::transform($setting, TransformScope::Static, $registry)->resultType(null, $params);

            if (in_array($column, array_column($mappings, 'column'), true)) {
                throw ValidationException::withMessages([
                    'data.schema' => __('phpinnacle-ferry::resources.sync.errors.constant_column_taken', [
                        'column' => $column,
                    ]),
                ]);
            }

            if ($destination !== null) {
                self::assertCompatibleWithDestination($fields, $column, $type);
            }

            $mappings[] = new FieldMapping(null, $column, null, $type, [$setting]);
        }

        if ($destination !== null) {
            self::assertRequiredFieldsMapped($fields, $mappings);
        }

        return $mappings;
    }

    /**
     * @param list<TransformSetting> $settings
     * @return list<TransformSetting>
     */
    public static function fromTransforms(
        array $settings,
        ConnectionMetadata $object,
        TransformFactory $registry,
    ): array {
        $normalized = [];

        foreach ($settings as $setting) {
            $setting = new TransformSetting(
                $setting->key,
                $registry->getOrFail($setting->key)->normalize($setting->params, $object),
            );
            $transform = self::transform($setting, TransformScope::Record, $registry);

            foreach ($transform->sourceColumns($setting->params) as $column) {
                if (!$object->hasColumn($column)) {
                    throw ValidationException::withMessages([
                        'data.transforms' => __('phpinnacle-ferry::resources.sync.errors.transform_column_missing', [
                            'column' => $column,
                        ]),
                    ]);
                }
            }

            $normalized[] = $setting;
        }

        return $normalized;
    }

    private static function transform(
        TransformSetting $setting,
        TransformScope $scope,
        TransformFactory $registry,
    ): Transform {
        $transform = $registry->getOrFail($setting->key);
        $attribute = $scope === TransformScope::Static ? 'data.schema' : 'data.transforms';

        if ($transform->scope !== $scope) {
            throw ValidationException::withMessages([
                $attribute => __('phpinnacle-ferry::resources.sync.errors.transform_scope_mismatch', [
                    'transform' => $transform->getLabel(),
                ]),
            ]);
        }

        $validator = Validator::make($setting->params, $transform->rules($setting->params));

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                $attribute => __('phpinnacle-ferry::resources.sync.errors.transform_invalid_params', [
                    'transform' => $transform->getLabel(),
                    'error' => $validator->errors()->first(),
                ]),
            ]);
        }

        return $transform;
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
