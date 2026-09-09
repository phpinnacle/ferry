<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Franz\Client;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Franz\Request\ConnectorConfigRequest;
use PHPinnacle\Franz\Response\ConnectorStatusResponse;
use PHPinnacle\Franz\Response\TaskStatusResponse;

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

        $name = $this->sink->name($sync);

        $previousColumns = $this->previousColumns($connection);

        $config = $this->pushSource($connection, $this->syncsWith($connection, $sync));

        $this->client
            ->connector($name)
            ->updateConfig(new ConnectorConfigRequest($this->sink->build($sync)));

        if ($previousColumns !== null) {
            $this->signaler->requestSnapshot($connection, $this->newlyScopedTables($previousColumns, $config));
        }

        $this->client->connector($this->source->name($connection))->resume();
        $this->client->connector($name)->resume();

        $sync->activate();

        $this->sourceStatus($connection);
        $this->sinkStatus($sync);
    }

    public function delete(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->deleteConnector($this->sink->name($sync));
        $this->pushSource($connection, $this->syncsWithout($connection, $sync));
    }

    public function deleteSource(Connection $connection): void
    {
        $this->deleteConnector($this->source->name($connection));
    }

    public function pause(Sync $sync, bool $manually = true): void
    {
        $connection = $sync->connection;

        $this->assertPgsqlDriver($connection);

        try {
            $this->client->connector($this->sink->name($sync))->pause();
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

        $this->client->connector($this->source->name($connection))->restart(includeTasks: true, onlyFailed: true);
        $this->client->connector($this->sink->name($sync))->restart(includeTasks: true, onlyFailed: true);

        $this->sourceStatus($connection);
        $this->sinkStatus($sync);
    }

    public function sinkStatus(Sync $sync): void
    {
        $status = $this->status($this->sink->name($sync));

        $sync->recordSinkConnectorStatus($status['status'], $status['error']);
    }

    public function sourceStatus(Connection $connection): void
    {
        $status = $this->status($this->source->name($connection));

        $connection->recordSourceConnectorStatus($status['status'], $status['error']);
    }

    private function deleteConnector(string $name): void
    {
        try {
            $this->client->connector($name)->delete();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }
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
        $connector = $this->client->connector($this->source->name($connection));

        $config = $this->source->build($connection, $syncs);

        $connector->updateConfig(new ConnectorConfigRequest($config));

        if ($syncs->isEmpty()) {
            $connector->pause();
        }

        return $config;
    }

    /** @return list<string>|null */
    private function previousColumns(Connection $connection): ?array
    {
        try {
            $config = $this->client->connector($this->source->name($connection))->config();
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

    /** @return array{status: ConnectorStatus, error: string|null} */
    private function status(string $name): array
    {
        try {
            $response = $this->client->connector($name)->status();

            $failedTask = $this->failedTask($response);

            if ($failedTask !== null) {
                return ['status' => ConnectorStatus::Failed, 'error' => $failedTask->trace];
            }

            return [
                'status' => ConnectorStatus::fromConnectState($response->connector->state),
                'error' => $response->connector->trace,
            ];
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            return ['status' => ConnectorStatus::Unknown, 'error' => null];
        }
    }

    private function failedTask(ConnectorStatusResponse $response): ?TaskStatusResponse
    {
        foreach ($response->tasks as $task) {
            if (strtoupper($task->state) === 'FAILED') {
                return $task;
            }
        }

        return null;
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
