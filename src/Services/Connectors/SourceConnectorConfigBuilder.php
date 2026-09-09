<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncTableManager;

class SourceConnectorConfigBuilder
{
    public const array CONVERTERS = [
        'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
        'key.converter.schemas.enable' => 'true',
        'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
        'value.converter.schemas.enable' => 'true',
    ];

    private const string DEFAULT_SCHEMA = 'public';

    private const int IDENTIFIER_LENGTH = 63;

    /**
     * @param Collection<int, Sync> $syncs
     *
     * @return array<string, string>
     */
    public function build(Connection $connection, Collection $syncs): array
    {
        $tables = [];
        $columns = [];
        $keys = [];

        $objects = $this->objects($connection, $syncs);

        foreach ($syncs as $sync) {
            $object = $objects->get($sync->source);

            if (!$object instanceof ConnectionMetadata) {
                continue;
            }

            $table = $this->table($connection, $object);

            $tables[$table] = true;
            $keys[$table] = sprintf('%s:%s', $table, ConnectionMetadata::KEY_COLUMN);

            foreach (array_keys($this->columnMap($object, $sync)) as $column) {
                $columns[sprintf('%s.%s', $table, $column)] = true;
            }
        }

        $scope = $tables === []
            ? []
            : [
                'table.include.list' => implode(',', array_keys($tables)),
                'column.include.list' => implode(',', array_keys($columns)),
                'message.key.columns' => implode(';', array_values($keys)),
            ];

        return [
            'connector.class' => 'io.debezium.connector.postgresql.PostgresConnector',
            'tasks.max' => '1',
            'database.hostname' => $connection->host,
            'database.port' => (string) $connection->port,
            'database.user' => $connection->username,
            'database.password' => $connection->password,
            'database.dbname' => $connection->database,
            'plugin.name' => 'pgoutput',
            'slot.name' => $this->identifier('ferry_slot', $connection),
            'publication.name' => $this->identifier('ferry_pub', $connection),
            'publication.autocreate.mode' => 'filtered',
            'topic.prefix' => $this->topicPrefix($connection),
            'binary.handling.mode' => 'hex',
            'read.only' => 'true',
            'signal.enabled.channels' => 'kafka',
            'signal.kafka.topic' => Config::string('phpinnacle-ferry.kafka_connect.signal.topic'),
            'signal.kafka.bootstrap.servers' => Config::string(
                'phpinnacle-ferry.kafka_connect.signal.bootstrap_servers',
            ),
            'signal.kafka.groupId' => Config::string('phpinnacle-ferry.kafka_connect.signal.group_id'),
            ...$scope,
            ...self::CONVERTERS,
        ];
    }

    /** @return array<string, string> */
    public function columnMap(ConnectionMetadata $object, Sync $sync): array
    {
        $columns = [ConnectionMetadata::KEY_COLUMN => SyncTableManager::ID_COLUMN];

        foreach ($sync->schema as $mapping) {
            $column = $object->physicalColumn($mapping->source);

            if ($column !== null && $column !== ConnectionMetadata::KEY_COLUMN) {
                $columns[$column] = $mapping->column;
            }
        }

        return $columns;
    }

    public function name(Connection $connection): string
    {
        return sprintf('ferry-source-%s', $connection->code);
    }

    public function signalKey(Connection $connection): string
    {
        return $this->topicPrefix($connection);
    }

    public function table(Connection $connection, ConnectionMetadata $object): string
    {
        return sprintf('%s.%s', $connection->schema ?? self::DEFAULT_SCHEMA, $object->name);
    }

    public function topic(Connection $connection, ConnectionMetadata $object): string
    {
        return sprintf('%s.%s', $this->topicPrefix($connection), $this->table($connection, $object));
    }

    private function identifier(string $prefix, Connection $connection): string
    {
        $code = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($connection->code)) ?? '';

        return mb_substr(sprintf('%s_%s', $prefix, trim($code, '_')), 0, self::IDENTIFIER_LENGTH);
    }

    /**
     * @param Collection<int, Sync> $syncs
     *
     * @return Collection<string, ConnectionMetadata>
     */
    private function objects(Connection $connection, Collection $syncs): Collection
    {
        /** @var Collection<string, ConnectionMetadata> */
        return $connection
            ->publishedMetadata()
            ->whereIn('external_id', $syncs->pluck('source')->all())
            ->get()
            ->keyBy('external_id');
    }

    private function topicPrefix(Connection $connection): string
    {
        return sprintf('ferry.%s', $connection->code);
    }
}
