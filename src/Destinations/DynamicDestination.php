<?php

namespace PHPinnacle\Ferry\Destinations;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\JdbcConnectorConfig;
use PHPinnacle\Ferry\Services\TransformFactory;

final readonly class DynamicDestination implements Destination
{
    public function __construct(
        private string $table,
    ) {}

    public function getLabel(): string
    {
        return $this->table;
    }

    public function getIcon(): ?string
    {
        return null;
    }

    public function getColor(): ?string
    {
        return null;
    }

    public function connector(
        Sync $sync,
        ConnectionMetadata $object,
        string $topic,
        TransformFactory $transforms,
    ): array {
        return new JdbcConnectorConfig(
            table: $this->table,
            topic: $topic,
            columns: [...array_values($sync->columnMap($object)), ...$sync->constantColumns()],
            transforms: $transforms->pipeline($sync, $object, deletes: true),
            deletes: true,
        )->toArray();
    }
}
