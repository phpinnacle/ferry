<?php

namespace PHPinnacle\Ferry\Origins;

use LogicException;
use PHPinnacle\Ferry\Contracts\Origin;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\Driver as DatabaseDriver;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Origins\Kafka\KafkaConnectFactory;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Rules\ReplicaSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Franz\Request\ConnectorConfigRequest;

class KafkaOrigin implements Origin
{
    /**
     * @param array{
     *     target_host: ?string,
     *     source: array<string, string>,
     *     sink: array<string, string>,
     *     signal: array{topic: string, bootstrap_servers: string, group_id: string, ...},
     *     ...
     * } $config
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
        private readonly KafkaConnectFactory $kafkaConnect,
        private readonly SourceFactory $sources,
        private readonly DestinationFactory $destinations,
        private readonly SinkConfigFactory $sinks,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function make(Upstream|Replica $source): Connector
    {
        $upstream = $source instanceof Upstream ? $source : $source->upstream;
        $this->assertPgsqlDriver($upstream);

        if ($source instanceof Upstream) {
            $config = $this->sources->source($source, $this->config);
        } else {
            $object = $this->sourceObject($source);
            $config = $this->sinks->make(
                $this->destinations->resolve($source),
                $source,
                $object,
                $this->sources->topic($upstream, $object),
                $this->config['target_host'],
            );
        }

        return ($source->connector ?? new Connector)->fill([
            'name' => $source->connector->name ?? $this->sources->name($source),
            'origin' => $this->name,
            'config' => [...$config, ...$this->config[$source instanceof Upstream ? 'source' : 'sink']],
        ]);
    }

    public function create(Connector $connector): void
    {
        $this->push($connector);
    }

    public function update(Connector $connector): void
    {
        $this->push($connector);

        /** @var array<string, string> $config */
        $config = $connector->config;
        /** @var array<string, string> $previous */
        $previous = $connector->applied_config;

        if ($config['connector.class'] === 'io.debezium.connector.postgresql.PostgresConnector') {
            $this->requestSnapshot(
                $config['topic.prefix'],
                $this->sources->newlyScopedTables($previous, $config),
            );
        }
    }

    public function start(Connector $connector): void
    {
        $remote = $this->kafkaConnect->client($this->config)->connector($connector->name);

        if ($connector->status === ConnectorStatus::Failed) {
            $remote->restart(includeTasks: true, onlyFailed: true);
        }

        $remote->resume();
    }

    public function pause(Connector $connector): void
    {
        try {
            $this->kafkaConnect->client($this->config)->connector($connector->name)->pause();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }
    }

    public function remove(Connector $connector): void
    {
        try {
            $this->kafkaConnect->client($this->config)->connector($connector->name)->delete();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }
    }

    public function status(Connector $connector): ConnectorStatus
    {
        $connector->error = null;

        try {
            $response = $this->kafkaConnect->client($this->config)->connector($connector->name)->status();
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }

            return ConnectorStatus::Unknown;
        }

        foreach ($response->tasks as $task) {
            if (strtoupper($task->state) === 'FAILED') {
                $connector->error = $task->trace;

                return ConnectorStatus::Failed;
            }
        }

        $connector->error = $response->connector->trace;

        return match (strtoupper($response->connector->state)) {
            'RUNNING' => ConnectorStatus::Running,
            'PAUSED', 'STOPPED' => ConnectorStatus::Paused,
            'FAILED' => ConnectorStatus::Failed,
            default => ConnectorStatus::Unknown,
        };
    }

    private function push(Connector $connector): void
    {
        /** @var array<string, string> $config */
        $config = $connector->config;

        $this->kafkaConnect
            ->client($this->config)
            ->connector($connector->name)
            ->updateConfig(new ConnectorConfigRequest($config));
    }

    private function assertPgsqlDriver(Upstream $upstream): void
    {
        if ($upstream->driver !== DatabaseDriver::Pgsql) {
            throw new LogicException(__('phpinnacle-ferry::resources.upstream.errors.unsupported_driver', [
                'driver' => $upstream->driver->getLabel(),
            ]));
        }
    }

    private function sourceObject(Replica $replica): StructureItem
    {
        $object = $replica->upstream->structure->publishedItem($replica->source);

        if ($object === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.replica.errors.source_object_missing', [
                'code' => $replica->code,
            ]));
        }

        ReplicaSchema::assertValid(
            $replica->mapping,
            $this->destinations->staticFor($replica),
            $replica->primaryKeySources($object),
            $replica->schema,
            $object->primary_key,
        );

        return $object;
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

        /** @var array{topic: string} $signal */
        $signal = $this->config['signal'];

        $this->kafkaConnect->signals($this->config)->publish(
            $signal['topic'],
            $key,
            $payload,
        );
    }
}
