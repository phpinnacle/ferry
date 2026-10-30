<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;

class ReplicaSchemaResolver
{
    public function __construct(
        private readonly TransformRegistry $transforms,
    ) {}

    public function transformedSchema(Replica $replica): ?JsonSchema
    {
        return $this->transforms->resultSchema(
            $replica->schema,
            $replica->transforms,
            null,
            TransformScope::Record,
        );
    }

    public function originalSchema(Replica $replica): ?JsonSchema
    {
        /** @var JsonSchema $schema */
        $schema = $replica->getOriginal('schema');
        /** @var list<TransformSetting> $transforms */
        $transforms = $replica->getOriginal('transforms');

        return $this->transforms->resultSchema($schema, $transforms, null, TransformScope::Record);
    }

    public function outputSchema(Replica $replica, FieldMapping $mapping, ?JsonSchema $schema = null): ?JsonSchema
    {
        $schema ??= $this->transformedSchema($replica);

        return $schema === null ? null : $this->transforms->mappingSchema($mapping, $schema);
    }

    /** @return list<string> */
    public function primaryKeySources(Replica $replica, StructureItem $object): array
    {
        /** @var list<string> */
        return $this->transformedSchema($replica)?->annotation('x-primary-key') ?? $object->primary_key;
    }
}
