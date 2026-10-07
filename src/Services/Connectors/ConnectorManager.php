<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\SignalProducer;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\SourceFactory;
use PHPinnacle\Franz\Client;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Franz\Request\ConnectorConfigRequest;

class ConnectorManager
{
    public function __construct(
        private readonly Client $client,
        private readonly SourceFactory $sources,
        private readonly DestinationFactory $destinations,
        private readonly SignalProducer $signals,
    ) {}

    public function activate(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->assertPgsqlDriver($connection);
        $object = $this->sourceObject($sync);

        $sink = $this->connector($sync);

        $previousColumns = $this->previousColumns($connection);

        $config = $this->pushSource($connection, $connection->trackedSyncs()->except([$sync->id])->push($sync));

        $this->pushConfig($sink, $this->destinations->resolve($sync)->connector(
            $sync,
            $object,
            $this->sources->topic($connection, $object),
        ));
        $sync->recordConnector($sink);

        if ($previousColumns !== null) {
            $this->requestSnapshot($config['topic.prefix'], $this->newlyScopedTables($previousColumns, $config));
        }

        $this->client->connector($this->connector($connection)->name)->resume();
        $this->client->connector($sink->name)->resume();

        $sync->activate();

        $this->checkStatus($connection);
        $this->checkStatus($sync);
    }

    public function delete(Sync $sync): void
    {
        $connection = $sync->connection;

        $this->deleteConnector($this->connector($sync));
        $sync->connector()->dissociate();
        $this->pushSource($connection, $connection->trackedSyncs()->except([$sync->id]));
    }

    public function deleteSource(Connection $connection): void
    {
        $this->deleteConnector($this->connector($connection));
        $connection->connector()->dissociate();
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
        $this->checkStatus($sync);
    }

    public function refreshSource(Connection $connection): void
    {
        $this->assertPgsqlDriver($connection);

        $this->pushSource($connection, $connection->trackedSyncs());
        $this->checkStatus($connection);
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

        $this->checkStatus($connection);
        $this->checkStatus($sync);
    }

    public function checkStatus(Connection|Sync $owner): void
    {
        $connector = $this->connector($owner);
        $this->refreshStatus($connector);
        $owner->recordConnector($connector);
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

    private function sourceObject(Sync $sync): ConnectionMetadata
    {
        $object = $sync->connection->publishedObject($sync->source);

        if ($object === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.source_object_missing', [
                'code' => $sync->code,
            ]));
        }

        if (!$sync->hasValidSchema($object, $this->destinations->get($sync->static_destination)?->fields)) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.invalid_mapping', [
                'code' => $sync->code,
            ]));
        }

        return $object;
    }

    /**
     * @param Collection<int, Sync> $syncs
     *
     * @return array<string, string>
     */
    private function pushSource(Connection $connection, Collection $syncs): array
    {
        $record = $this->connector($connection);
        $config = $this->sources->source($connection, $syncs);

        $this->pushConfig($record, $config);
        $connection->recordConnector($record);

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
        return (
            $owner->connector ?? new Connector([
                'name' => $this->sources->name($owner),
            ])
        );
    }

    /** @param list<string> $tables */
    private function requestSnapshot(string $key, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $payload = json_encode([
            'type' => 'execute-snapshot',
            'data' => [
                'data-collections' => $tables,
                'type' => 'INCREMENTAL',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->signals->publish(
            Config::string('phpinnacle-ferry.kafka_connect.signal.topic'),
            $key,
            $payload,
        );
    }
}
