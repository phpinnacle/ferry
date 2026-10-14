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
        ['before' => $before, 'after' => $after] = $transforms->pipeline($sync, $object);

        return new JdbcConnectorConfig(
            table: $this->table,
            topic: $topic,
            columns: $sync->columnMap($object),
            deletes: true,
            before: $before,
            after: $after,
            constants: $sync->constantColumns(),
        )->toArray();
    }
}
