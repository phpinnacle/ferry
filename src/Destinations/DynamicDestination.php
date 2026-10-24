<?php

namespace PHPinnacle\Ferry\Destinations;

use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\JdbcConnectorConfig;
use PHPinnacle\Ferry\Services\TransformFactory;

final readonly class DynamicDestination implements Destination
{
    public string $table;

    public function __construct(Sync $sync)
    {
        $this->table = $sync->exists
            ? $sync->destination
            : Config::string('phpinnacle-ferry.sync.table_prefix') . $sync->code;
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
