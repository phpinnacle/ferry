<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Franz\Client;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Franz\Request\ConnectorConfigRequest;

class ConnectorManager
{
    public function __construct(
        private readonly Client $client,
        private readonly SourceConnectorConfigBuilder $source,
        private readonly SinkConnectorConfigBuilder $sink,
        private readonly SnapshotSignaler $signaler,
        private readonly SyncReviewer $reviewer,
    ) {}

    public function activate(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->assertPgsqlDriver($connection);
        $this->assertValidMapping($sync);

        $sink = $this->connector($sync);

        $previousColumns = $this->previousColumns($connection);

        $config = $this->pushSource($connection, $this->syncsWith($connection, $sync));

        $this->pushConfig($sink, $this->sink->build($sync));
        $sync->setRelation('connector', $sink);

        if ($previousColumns !== null) {
            $this->signaler->requestSnapshot($connection, $this->newlyScopedTables($previousColumns, $config));
        }

        $this->client->connector($this->connector($connection)->name)->resume();
        $this->client->connector($sink->name)->resume();

        $sync->activate();

        $this->sourceStatus($connection);
        $this->sinkStatus($sync);
    }

    public function delete(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->deleteConnector($this->connector($sync));
        $sync->setRelation('connector', null);
        $this->pushSource($connection, $this->syncsWithout($connection, $sync));
    }

    public function deleteSource(Connection $connection): void
    {
        $this->deleteConnector($this->connector($connection));
        $connection->setRelation('connector', null);
    }

    public function pause(Sync $sync, bool $manually = true): void
    {
        $connection = $sync->connection;

        $this->assertPgsqlDriver($connection);

        try {
            $this->client->connector($this->connector($sync)->name)->pause();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }

        $sync->pause($manually);

        $this->pushSource($connection, $connection->trackedSyncs());
        $this->sinkStatus($sync);
    }

    public function refreshSource(Connection $connection): void
    {
        $this->assertPgsqlDriver($connection);

        $this->pushSource($connection, $connection->trackedSyncs());
        $this->sourceStatus($connection);
    }

    public function restart(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->assertPgsqlDriver($connection);

        $this->client->connector($this->connector($connection)->name)->restart(
            includeTasks: true,
            onlyFailed: true,
        );
        $this->client->connector($this->connector($sync)->name)->restart(includeTasks: true, onlyFailed: true);

        $this->sourceStatus($connection);
        $this->sinkStatus($sync);
    }

    public function sinkStatus(Sync $sync): void
    {
        $connector = $this->connector($sync);
        $this->refreshStatus($connector);
        $sync->setRelation('connector', $connector);
    }

    public function sourceStatus(Connection $connection): void
    {
        $connector = $this->connector($connection);
        $this->refreshStatus($connector);
        $connection->setRelation('connector', $connector);
    }

    private function deleteConnector(Connector $connector): void
    {
        try {
            $this->client->connector($connector->name)->delete();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }

        $connector->delete();
    }

    private function assertPgsqlDriver(Connection $connection): void
    {
        if ($connection->driver !== Driver::Pgsql) {
            throw new LogicException(__('phpinnacle-ferry::resources.connection.errors.unsupported_driver', [
                'driver' => $connection->driver->getLabel(),
            ]));
        }
    }

    private function assertValidMapping(Sync $sync): void
    {
        $object = $sync->connection->publishedObject($sync->source);

        if ($object instanceof ConnectionMetadata && !$this->reviewer->isValid($sync, $object)) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.invalid_mapping', [
                'code' => $sync->code,
            ]));
        }
    }

    /**
     * @param Collection<int, Sync> $syncs
     *
     * @return array<string, string>
     */
    private function pushSource(Connection $connection, Collection $syncs): array
    {
        $record = $this->connector($connection);
        $config = $this->source->build($connection, $syncs);

        $this->pushConfig($record, $config);
        $connection->setRelation('connector', $record);

        if ($syncs->isEmpty()) {
            $this->client->connector($record->name)->pause();
        }

        return $config;
    }

    /** @param array<string, string> $config */
    private function pushConfig(Connector $connector, array $config): void
    {
        $this->client->connector($connector->name)->updateConfig(new ConnectorConfigRequest($config));
        $connector->recordConfig($config);
    }

    /** @return list<string>|null */
    private function previousColumns(Connection $connection): ?array
    {
        try {
            $config = $this->client->connector($this->connector($connection)->name)->config();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            return null;
        }

        return $this->columns($config);
    }

    /**
     * @param array<string, string> $config
     *
     * @return list<string>
     */
    private function columns(array $config): array
    {
        $list = $config['column.include.list'] ?? '';

        return $list === '' ? [] : explode(',', $list);
    }

    /**
     * @param list<string> $previousColumns
     * @param array<string, string> $config
     *
     * @return list<string>
     */
    private function newlyScopedTables(array $previousColumns, array $config): array
    {
        $tables = [];

        foreach (array_diff($this->columns($config), $previousColumns) as $column) {
            $parts = explode('.', $column);
            array_pop($parts);

            $tables[implode('.', $parts)] = true;
        }

        return array_keys($tables);
    }

    private function refreshStatus(Connector $connector): void
    {
        try {
            $response = $this->client->connector($connector->name)->status();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            $connector->recordStatus(ConnectorStatus::Unknown);

            return;
        }

        foreach ($response->tasks as $task) {
            if (strtoupper($task->state) === 'FAILED') {
                $connector->recordStatus(ConnectorStatus::Failed, $task->trace);

                return;
            }
        }

        $connector->recordStatus(
            ConnectorStatus::fromConnectState($response->connector->state),
            $response->connector->trace,
        );
    }

    private function connector(Connection|Sync $owner): Connector
    {
        return $owner->connector ?? $owner
            ->connector()
            ->make([
                'name' => $owner instanceof Connection ? $this->source->name($owner) : $this->sink->name($owner),
            ]);
    }

    /** @return Collection<int, Sync> */
    private function syncsWith(Connection $connection, Sync $sync): Collection
    {
        $syncs = $this->syncsWithout($connection, $sync);
        $syncs->push($sync);

        return $syncs;
    }

    /** @return Collection<int, Sync> */
    private function syncsWithout(Connection $connection, Sync $sync): Collection
    {
        return $connection
            ->trackedSyncs()
            ->reject(fn (Sync $item) => $item->is($sync))
            ->values();
    }
}
