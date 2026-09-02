<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Connection as DatabaseConnection;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Rosetta\Connections\PdoConnection;
use PHPinnacle\Rosetta\Data\MetadataDefinition;
use PHPinnacle\Rosetta\MetadataLoader;
use PHPinnacle\Rosetta\StorageMap;
use PHPinnacle\Rosetta\TypeMap;

class StructureImporter
{
    private const array UPDATED_COLUMNS = [
        'parent_id',
        'reference_id',
        'name',
        'code',
        'kind',
        'label',
        'title',
        'system',
        'properties',
        'values',
        'position',
    ];

    private const array UNIQUE_COLUMNS = [
        'connection_id',
        'external_id',
        'revision',
    ];

    public function __construct(
        private readonly SourceConnectionFactory $factory,
        private readonly MetadataLoader $loader,
    ) {}

    public function importChunk(
        Connection $connection,
        StorageMap $storageMap,
        TypeMap $typeMap,
        int $offset,
        int $limit,
    ): int {
        $source = $this->source($connection);

        try {
            $metadata = $this->loader->chunk(
                new PdoConnection($source->getPdo()),
                $storageMap,
                $typeMap,
                $offset,
                $limit,
            );

            if ($metadata !== []) {
                $this->persist($metadata, $connection, $offset);
            }

            return count($metadata);
        } finally {
            $source->disconnect();
        }
    }

    /**
     * @return array{storageMap: StorageMap, typeMap: TypeMap, total: int}
     */
    public function prepare(Connection $connection): array
    {
        $source = $this->source($connection);

        try {
            $rosetta = new PdoConnection($source->getPdo());
            $storageMap = $this->loader->storageMap($rosetta);

            return [
                'storageMap' => $storageMap,
                'typeMap' => $this->loader->typeMap($rosetta),
                'total' => $this->loader->count($storageMap),
            ];
        } finally {
            $source->disconnect();
        }
    }

    /**
     * @param  list<MetadataDefinition>  $metadata
     * @return array<string, string>
     */
    private function identifiers(array $metadata, Connection $connection, int $revision): array
    {
        $externalIds = [];

        foreach ($metadata as $object) {
            $externalIds[] = $object->id;

            foreach ($object->sections as $section) {
                $externalIds[] = $section->id;
            }
        }

        /** @var array<string, string> $ids */
        $ids = ConnectionMetadata::query()
            ->where('connection_id', $connection->id)
            ->where('revision', $revision)
            ->whereIn('external_id', $externalIds)
            ->pluck('id', 'external_id')
            ->all();

        $model = new ConnectionMetadata;

        foreach ($externalIds as $externalId) {
            $ids[$externalId] ??= $model->newUniqueId();
        }

        return $ids;
    }

    /**
     * @param  list<MetadataDefinition>  $metadata
     */
    private function persist(array $metadata, Connection $connection, int $offset): void
    {
        $connection
            ->getConnection()
            ->transaction(function () use ($metadata, $connection, $offset) {
                $currentConnection = Connection::query()
                    ->whereKey($connection->id)
                    ->where('status', StructureStatus::Preparing)
                    ->where('run_id', $connection->run_id)
                    ->lockForUpdate()
                    ->first();

                if ($currentConnection === null) {
                    return;
                }

                $revision = $currentConnection->draftRevision();
                $ids = $this->identifiers($metadata, $currentConnection, $revision);

                $roots = [];
                $sections = [];

                foreach ($metadata as $position => $object) {
                    $roots[] = $this->row(
                        $object,
                        $ids[$object->id],
                        $currentConnection->id,
                        $revision,
                        $offset + $position,
                    );

                    foreach ($object->sections as $sectionPosition => $section) {
                        $sections[] = $this->row(
                            $section,
                            $ids[$section->id],
                            $currentConnection->id,
                            $revision,
                            $sectionPosition,
                            $ids[$object->id],
                        );
                    }
                }

                ConnectionMetadata::query()->upsert($roots, self::UNIQUE_COLUMNS, self::UPDATED_COLUMNS);

                if ($sections !== []) {
                    ConnectionMetadata::query()->upsert($sections, self::UNIQUE_COLUMNS, self::UPDATED_COLUMNS);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        MetadataDefinition $object,
        string $id,
        string $connectionId,
        int $revision,
        int $position,
        ?string $parentId = null,
    ): array {
        return [
            'id' => $id,
            'connection_id' => $connectionId,
            'parent_id' => $parentId,
            'external_id' => $object->id,
            'reference_id' => $object->referenceId,
            'name' => $object->name,
            'code' => $object->code,
            'kind' => $object->kind->value,
            'label' => $object->label,
            'title' => $object->title,
            'system' => json_encode($object->system, JSON_THROW_ON_ERROR),
            'properties' => json_encode($object->properties, JSON_THROW_ON_ERROR),
            'values' => json_encode($object->values, JSON_THROW_ON_ERROR),
            'position' => $position,
            'revision' => $revision,
        ];
    }

    private function source(Connection $connection): DatabaseConnection
    {
        return $this->factory->make($connection->credentials());
    }
}
