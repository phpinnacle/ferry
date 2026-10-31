<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
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
        ?TransformTarget $target = TransformTarget::Value,
        TransformScope $scope = TransformScope::Field,
    ): ?Schema {
        foreach ($settings as $setting) {
            $transform = $this->transforms->get($setting->type);
            if (
                $transform->getScope() !== $scope
                || $target !== null
                && $transform->getTarget() !== $target
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
            $next = $this->resultSchema($schema, [$setting], null, TransformScope::Record);
            if ($next === null) {
                break;
            }

            $schema = $next;
        }

        return $schema;
    }

    public function mappingSchema(FieldMapping $mapping, Schema $schema): ?Schema
    {
        /** @var string $source */
        $source = $mapping->source;
        $input = $mapping->kind === FieldKind::Static
            ? $mapping->staticSchema()
            : $schema->resolveReferences()->effectiveProperties()[$source];
        /** @var list<string> $primaryKey */
        $primaryKey = $schema->annotation('x-primary-key') ?? [];

        return $this->resultSchema(
            $input,
            $mapping->transforms(),
            in_array($source, $primaryKey, true)
                ? TransformTarget::Key
                : TransformTarget::Value,
        );
    }

    public function transformedSchema(Replica $replica): ?Schema
    {
        return $this->resultSchema(
            $replica->schema,
            $replica->transforms,
            null,
            TransformScope::Record,
        );
    }

    public function originalSchema(Replica $replica): ?Schema
    {
        /** @var Schema $schema */
        $schema = $replica->getOriginal('schema');
        /** @var list<TransformSetting> $transforms */
        $transforms = $replica->getOriginal('transforms');

        return $this->resultSchema($schema, $transforms, null, TransformScope::Record);
    }

    public function outputSchema(Replica $replica, FieldMapping $mapping, ?Schema $schema = null): ?Schema
    {
        $schema ??= $this->transformedSchema($replica);

        return $schema === null ? null : $this->mappingSchema($mapping, $schema);
    }

    /** @return list<string> */
    public function primaryKeySources(Replica $replica, StructureItem $object): array
    {
        /** @var list<string> */
        return $this->transformedSchema($replica)?->annotation('x-primary-key') ?? $object->primary_key;
    }
}
