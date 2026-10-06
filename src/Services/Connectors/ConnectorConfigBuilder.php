<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;

class ConnectorConfigBuilder
{
    private const array CONVERTERS = [
        'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
        'key.converter.schemas.enable' => 'true',
        'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
        'value.converter.schemas.enable' => 'true',
    ];

    private const string DEFAULT_SCHEMA = 'public';

    private const int IDENTIFIER_LENGTH = 63;

    public function __construct(
        private readonly StaticDestinationRegistry $destinations,
    ) {}

    /**
     * @param Collection<int, Sync> $syncs
     *
     * @return array<string, string>
     */
    public function source(Connection $connection, Collection $syncs): array
    {
        $tables = [];
        $columns = [];
        $keys = [];

        $objects = $connection
            ->publishedMetadata()
            ->whereIn('external_id', $syncs->pluck('source')->all())
            ->get()
            ->keyBy('external_id');

        foreach ($syncs as $sync) {
            $object = $objects->get($sync->source);

            if (!$object instanceof ConnectionMetadata) {
                continue;
            }

            $table = $this->table($connection, $object);

            $tables[$table] = true;
            $keys[$table] = sprintf('%s:%s', $table, ConnectionMetadata::KEY_COLUMN);

            foreach (array_keys($sync->columnMap($object)) as $column) {
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

    public function name(Connection|Sync $owner): string
    {
        return sprintf('ferry-%s-%s', $owner instanceof Connection ? 'source' : 'sink', $owner->code);
    }

    public function table(Connection $connection, ConnectionMetadata $object): string
    {
        return sprintf('%s.%s', $connection->schema ?? self::DEFAULT_SCHEMA, $object->name);
    }

    public function topic(Connection $connection, ConnectionMetadata $object): string
    {
        return sprintf('%s.%s', $this->topicPrefix($connection), $this->table($connection, $object));
    }

    /** @return array<string, string> */
    public function sink(Sync $sync): array
    {
        $connection = $sync->connection;
        $object = $connection->publishedObject($sync->source);

        if (!$object instanceof ConnectionMetadata) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.source_object_missing', [
                'code' => $sync->code,
            ]));
        }

        $destination = $sync->static_destination === null
            ? null
            : $this->destinations->getOrFail($sync->static_destination);
        $columns = $sync->columnMap($object, $destination?->primaryKey() ?? Sync::ID_COLUMN);
        $target = $this->targetConnection($destination);
        $fixedValues = $destination?->fixedValues() ?? [];
        $deletes = $destination === null;
        $transforms = ['unwrap', 'rename_keys', 'rename_values'];
        $fixedTransforms = [];

        foreach ($fixedValues as $field => $value) {
            $name = 'insert_' . $field;
            $transforms[] = $name;
            $fixedTransforms['transforms.' . $name . '.type'] = 'org.apache.kafka.connect.transforms.InsertField$Value';
            $fixedTransforms['transforms.' . $name . '.static.field'] = $field;
            $fixedTransforms['transforms.' . $name . '.static.value'] = $value;
        }

        return [
            'connector.class' => 'io.confluent.connect.jdbc.JdbcSinkConnector',
            'tasks.max' => '1',
            'topics' => $this->topic($connection, $object),
            'table.name.format' => $destination?->table() ?? $sync->destination,
            'connection.url' => $target['url'],
            'connection.user' => $target['username'],
            'connection.password' => $target['password'],
            'pk.mode' => 'record_key',
            'insert.mode' => 'upsert',
            'delete.enabled' => $deletes ? 'true' : 'false',
            'auto.create' => 'false',
            'fields.whitelist' => implode(',', [...array_values($columns), ...array_keys($fixedValues)]),
            'transforms' => implode(',', $transforms),
            'transforms.unwrap.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.unwrap.delete.tombstone.handling.mode' => $deletes ? 'tombstone' : 'drop',
            'transforms.rename_keys.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
            'transforms.rename_keys.renames' => $this->renames([
                ConnectionMetadata::KEY_COLUMN => $columns[ConnectionMetadata::KEY_COLUMN],
            ]),
            'transforms.rename_values.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'transforms.rename_values.renames' => $this->renames($columns),
            ...$fixedTransforms,
            ...self::CONVERTERS,
        ];
    }

    /** @param array<string, string> $columns */
    private function renames(array $columns): string
    {
        $renames = [];

        foreach ($columns as $source => $destination) {
            $renames[] = sprintf('%s:%s', $source, $destination);
        }

        return implode(',', $renames);
    }

    /** @return array{url: string, username: string, password: string} */
    private function targetConnection(?StaticDestination $destination): array
    {
        /** @var string $name */
        $name =
            $destination?->connection() ?? Config::get('phpinnacle-ferry.connection') ?? Config::string(
                'database.default',
            );
        /** @var string|null $targetHost */
        $targetHost = Config::get('phpinnacle-ferry.target_host');
        $host =
            $targetHost !== null && $targetHost !== ''
                ? $targetHost
                : Config::string(sprintf('database.connections.%s.host', $name));
        /** @var int|numeric-string $port */
        $port = Config::get(sprintf('database.connections.%s.port', $name), 5432);
        $database = Config::string(sprintf('database.connections.%s.database', $name));

        return [
            'url' => sprintf('jdbc:postgresql://%s:%d/%s', $host, (int) $port, $database),
            'username' => Config::string(sprintf('database.connections.%s.username', $name), ''),
            'password' => Config::string(sprintf('database.connections.%s.password', $name), ''),
        ];
    }

    private function identifier(string $prefix, Connection $connection): string
    {
        $code = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($connection->code)) ?? '';

        return mb_substr(sprintf('%s_%s', $prefix, trim($code, '_')), 0, self::IDENTIFIER_LENGTH);
    }

    private function topicPrefix(Connection $connection): string
    {
        return sprintf('ferry.%s', $connection->code);
    }
}
