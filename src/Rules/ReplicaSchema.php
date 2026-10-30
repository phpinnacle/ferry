<?php

namespace PHPinnacle\Ferry\Rules;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Services\ReplicaTableManager;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Jason\Schema;

readonly class ReplicaSchema
{
    public function __construct(
        private TransformRegistry $transforms,
    ) {}

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

            if (array_key_exists($column, $used)) {
                $fail('phpinnacle-ferry::validation.replica_schema.column_duplicate')->translate([
                    'column' => $column,
                ]);

                return;
            }

            if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                $fail('phpinnacle-ferry::validation.replica_schema.column_format')->translate([
                    'column' => $column,
                ]);

                return;
            }

            $used[$column] = true;
        }
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

    /**
     * @param list<FieldMapping> $mappings
     * @param list<string> $sourcePrimaryKey
     */
    public function assertValid(
        array $mappings,
        ?StaticDestination $destination,
        array $sourcePrimaryKey,
        ?Schema $sourceSchema,
    ): void {
        if ($sourceSchema === null) {
            throw ValidationException::withMessages([
                'data.transforms' => __('phpinnacle-ferry::validation.replica_transforms'),
            ]);
        }

        $sources = [];
        foreach ($mappings as $mapping) {
            if ($mapping->source !== null && $mapping->target !== null) {
                $sources[] = $mapping->source;
            }
        }

        Validator::validate(['data' => ['mapping' => $sources]], ['data.mapping' => [
            fn (string $attribute, mixed $value, Closure $fail) => self::validatePrimaryKey(
                $sourcePrimaryKey,
                $sources,
                $fail,
            ),
        ]]);
        $destinationSchema = $destination?->getSchema() ?? Schema::fromArray([]);
        $fields = $destinationSchema->resolveReferences()->effectiveProperties();
        foreach ($mappings as $mapping) {
            $sourceIsKey = in_array($mapping->source, $sourcePrimaryKey, true);
            if ($mapping->target === null) {
                continue;
            }

            $output = $this->transforms->mappingSchema($mapping, $sourceSchema);
            if ($output === null || ReplicaTableManager::columnType($output) === null) {
                throw ValidationException::withMessages([
                    'data.mapping' => __('phpinnacle-ferry::resources.replica.errors.unsupported_field_type', [
                        'column' => $mapping->target,
                    ]),
                ]);
            }

            if ($destination !== null) {
                self::assertCompatibleWithDestination($fields, $mapping->target, $mapping, $output);
                if (
                    in_array($mapping->target, $destination->getPrimaryKey(), true) !== $sourceIsKey
                ) {
                    throw ValidationException::withMessages([
                        'data.mapping' => __(
                            $sourceIsKey
                                ? 'phpinnacle-ferry::resources.replica.errors.source_primary_key_destination'
                                : 'phpinnacle-ferry::resources.replica.errors.destination_primary_key_source',
                            [
                                'field' => $sourceIsKey ? $mapping->source : $mapping->target,
                            ],
                        ),
                    ]);
                }
            }
        }

        if ($destination !== null) {
            self::assertRequiredFieldsMapped($destinationSchema, $mappings);
        }
    }

    /** @param array<string, Schema> $fields */
    private static function assertCompatibleWithDestination(
        array $fields,
        string $column,
        FieldMapping $mapping,
        Schema $output,
    ): void {
        $field = $fields[$column] ?? null;

        if ($field === null) {
            throw ValidationException::withMessages([
                'data.mapping' => __('phpinnacle-ferry::resources.replica.errors.unknown_destination_field', [
                    'field' => $column,
                ]),
            ]);
        }

        if (!$mapping->canMapTo($output, $field)) {
            throw ValidationException::withMessages([
                'data.mapping' => __('phpinnacle-ferry::resources.replica.errors.incompatible_destination_field', [
                    'field' => $field->annotation('title') ?? $column,
                ]),
            ]);
        }
    }

    /** @param list<FieldMapping> $mappings */
    private static function assertRequiredFieldsMapped(Schema $schema, array $mappings): void
    {
        $mapped = array_map(static fn (FieldMapping $mapping) => $mapping->target, $mappings);
        $fields = $schema->resolveReferences()->effectiveProperties();

        foreach ($schema->effectiveRequired() as $column) {
            if (!in_array($column, $mapped, true)) {
                throw ValidationException::withMessages([
                    'data.mapping' => __(
                        'phpinnacle-ferry::resources.replica.errors.required_destination_field_missing',
                        ['field' => $fields[$column]->annotation('title') ?? $column],
                    ),
                ]);
            }
        }
    }
}
