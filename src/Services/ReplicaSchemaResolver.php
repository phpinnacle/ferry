<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Jason\Schema;

class ReplicaSchemaResolver
{
    public function __construct(
        private readonly TransformRegistry $transforms,
    ) {}

    /** @param list<TransformSetting> $settings */
    public function resultSchema(
        Schema $input,
        array $settings,
        TransformScope $scope = TransformScope::Field,
    ): ?Schema {
        foreach ($settings as $setting) {
            $transform = $this->transforms->get($setting->type);
            if (
                $transform->getScope() !== $scope
                || !$transform->accepts($input)
            ) {
                return null;
            }

            $input = $transform->output($input, $setting->data);
            if ($input === null) {
                return null;
            }
        }

        return $input;
    }

    /** @param list<TransformSetting> $settings */
    public function previewRecordSchema(Schema $schema, array $settings): Schema
    {
        foreach ($settings as $setting) {
            $next = $this->resultSchema($schema, [$setting], TransformScope::Record);
            if ($next === null) {
                break;
            }

            $schema = $next;
        }

        return $schema;
    }

    public function mappingSchema(FieldMapping $mapping, ?Schema $schema): ?Schema
    {
        if ($schema === null) {
            return null;
        }

        /** @var string $source */
        $source = $mapping->source;
        $input = $mapping->kind === FieldKind::Static
            ? $mapping->staticSchema()
            : $schema->resolveReferences()->effectiveProperties()[$source];

        return $this->resultSchema($input, $mapping->transforms());
    }

    public function transformedSchema(Replica $replica): ?Schema
    {
        return $this->resultSchema(
            $replica->schema,
            $replica->transforms,
            TransformScope::Record,
        );
    }

    public function originalSchema(Replica $replica): ?Schema
    {
        /** @var Schema $schema */
        $schema = $replica->getOriginal('schema');
        /** @var list<TransformSetting> $transforms */
        $transforms = $replica->getOriginal('transforms');

        return $this->resultSchema($schema, $transforms, TransformScope::Record);
    }

    public function outputSchema(Replica $replica, FieldMapping $mapping): ?Schema
    {
        return $this->mappingSchema($mapping, $this->transformedSchema($replica));
    }

    /** @return list<string> */
    public function primaryKeySources(Replica $replica): array
    {
        /** @var list<string> */
        return (
            $this->transformedSchema($replica)?->annotation('x-primary-key') ?? $replica->sourceObject()->primary_key
        );
    }

    /**
     * @param list<string> $sources
     * @return array{sources: list<string>, transforms: array<int, TransformSetting>}
     */
    public function keyPlan(Replica $replica, array $sources): array
    {
        if ($sources === [] || $replica->transforms === []) {
            return ['sources' => $sources, 'transforms' => []];
        }

        $schema = $replica->schema;
        $outputs = [];
        foreach ($replica->transforms as $index => $setting) {
            /** @var Schema $next */
            $next = $this->resultSchema($schema, [$setting], TransformScope::Record);
            $outputs[$index] = array_keys(array_diff_key($next->properties(), $schema->properties()));
            $schema = $next;
        }

        $transforms = [];
        foreach (array_reverse($replica->transforms, preserve_keys: true) as $index => $setting) {
            if (array_intersect($sources, $outputs[$index]) === []) {
                continue;
            }

            $inputs = $this->transforms->get($setting->type)->sourceFields($setting->data);
            $physical = [];
            foreach ($sources as $source) {
                array_push($physical, ...in_array($source, $outputs[$index], true) ? $inputs : [$source]);
            }

            $sources = $physical;
            $transforms[$index] = $setting;
        }

        ksort($transforms);

        return ['sources' => array_values(array_unique($sources)), 'transforms' => $transforms];
    }
}
