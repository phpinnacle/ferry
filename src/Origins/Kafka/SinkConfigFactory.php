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

    /**
     * @param array{
     *     target_host: ?string,
     *     sink: array{options: array<string, string>, transforms: array<string, array<string, string>>},
     *     ...
     * } $config
     * @return array<string, string>
     */
    public function make(
        Destination $destination,
        Replica $replica,
        StructureItem $object,
        string $topic,
        array $config,
    ): array {
        $primaryKey = $destination->getPrimaryKey();
        $keyMap = $replica->primaryKeyMap($primaryKey);
        $fixedValues = $destination->getFixedValues();

        $connector = new JdbcConnectorConfig(
            table: $destination->getTable(),
            topic: $topic,
            columns: [
                ...array_values($replica->columnMap()),
                ...$replica->constantColumns(),
                ...array_keys($fixedValues),
            ],
            transforms: $this->transforms->pipeline(
                $replica,
                $object,
                $keyMap,
                $fixedValues,
                $config['sink']['transforms'],
            ),
            connection: $destination->getConnectionName(),
            targetHost: $config['target_host'],
            primaryKey: $primaryKey,
        );

        return [...$connector->toArray(), ...$config['sink']['options']];
    }
}
