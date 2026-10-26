<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;

class SinkConfigFactory
{
    public function __construct(
        private readonly TransformFactory $transforms,
    ) {}

    /** @return array<string, string> */
    // @mago-expect lint:excessive-parameter-list
    public function make(
        Destination $destination,
        Replica $replica,
        StructureItem $object,
        string $topic,
        ?string $targetHost = null,
        bool $unwrap = false,
    ): array {
        $dynamic = $replica->type === DestinationType::Dynamic;
        $primaryKey = $destination->getPrimaryKey();
        $keyMap = $replica->primaryKeyMap($object);
        $fixedValues = $destination->getFixedValues();

        return new JdbcConnectorConfig(
            table: $destination->getTable(),
            topic: $topic,
            columns: [
                ...array_values($replica->columnMap($object)),
                ...$replica->constantColumns(),
                ...array_keys($fixedValues),
            ],
            transforms: $this->transforms->pipeline($replica, $object, $keyMap, $dynamic, $fixedValues, $unwrap),
            connection: $destination->getConnectionName(),
            targetHost: $targetHost,
            deletes: $dynamic,
            primaryKey: $primaryKey,
        )->toArray();
    }
}
