<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;

class SinkConfigFactory
{
    public function __construct(
        private readonly TransformFactory $transforms,
    ) {}

    /** @return array<string, string> */
    public function make(
        Destination $destination,
        Replica $replica,
        StructureItem $object,
        string $topic,
        ?string $targetHost = null,
    ): array {
        $primaryKey = $destination->getPrimaryKey();
        $keyMap = $replica->primaryKeyMap($primaryKey);
        $fixedValues = $destination->getFixedValues();

        return new JdbcConnectorConfig(
            table: $destination->getTable(),
            topic: $topic,
            columns: [
                ...array_values($replica->columnMap()),
                ...$replica->constantColumns(),
                ...array_keys($fixedValues),
            ],
            transforms: $this->transforms->pipeline($replica, $object, $keyMap, $fixedValues),
            connection: $destination->getConnectionName(),
            targetHost: $targetHost,
            primaryKey: $primaryKey,
        )->toArray();
    }
}
