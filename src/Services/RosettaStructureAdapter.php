<?php

namespace PHPinnacle\Ferry\Services;

use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\StructureAdapter;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\Rosetta\StructureConverter;
use PHPinnacle\Rosetta\Connections\PdoConnection;
use PHPinnacle\Rosetta\MetadataLoader;
use PHPinnacle\Rosetta\StorageMap;
use PHPinnacle\Rosetta\TypeMap;

class RosettaStructureAdapter implements StructureAdapter
{
    public function __construct(
        private readonly UpstreamFactory $factory,
        private readonly MetadataLoader $loader,
    ) {}

    public function getKey(): string
    {
        return 'rosetta';
    }

    public function getLabel(): string
    {
        return '1C Rosetta';
    }

    public function prepare(Upstream $upstream): StructurePlan
    {
        $source = $this->factory->make($upstream->credentials());

        try {
            $connection = new PdoConnection($source->getPdo());
            $storageMap = $this->loader->storageMap($connection);

            return new StructurePlan($this->loader->count($storageMap), [
                'storage_map' => $storageMap->toArray(),
                'type_map' => $this->loader->typeMap($connection)->toArray(),
            ]);
        } finally {
            $source->disconnect();
        }
    }

    /** @return list<StructureItem> */
    public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array
    {
        /** @var array{storage_map: array<string, array<string, int>>, type_map: array<string, list<array<mixed>>>} $context */
        $context = $plan->context;
        $source = $this->factory->make($upstream->credentials());

        try {
            $objects = $this->loader->chunk(
                new PdoConnection($source->getPdo()),
                StorageMap::fromArray($context['storage_map']),
                TypeMap::fromArray($context['type_map']),
                $offset,
                $limit,
            );

            $items = array_map(new StructureConverter()->item(...), $objects);
            $schema = $source->getSchemaBuilder();

            foreach ($items as $item) {
                foreach ([$item, ...$item->children] as $object) {
                    $primaryKey = array_find(
                        $schema->getIndexes($object->name),
                        fn (array $index) => $index['primary'],
                    );
                    $object->primary_key = $primaryKey['columns'] ?? [];
                    $object->schema = JsonSchema::fromArray([
                        ...$object->schema->toArray(),
                        'x-primary-key' => $object->primary_key,
                    ]);
                }
            }

            return $items;
        } finally {
            $source->disconnect();
        }
    }
}
