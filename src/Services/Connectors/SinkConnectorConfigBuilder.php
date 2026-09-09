<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\StaticDestination;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;

class SinkConnectorConfigBuilder
{
    public function __construct(
        private readonly SourceConnectorConfigBuilder $source,
        private readonly SyncDestinationResolver $destinations,
    ) {}

    /** @return array<string, string> */
    public function build(Sync $sync): array
    {
        $connection = $sync->connection;
        $object = $connection->publishedObject($sync->source);

        if (!$object instanceof ConnectionMetadata) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.source_object_missing', [
                'code' => $sync->code,
            ]));
        }

        $destination = $this->destinations->destination($sync->static_destination);
        $columns = $this->columns($object, $sync, $destination);
        $target = $this->targetConnection($destination);
        $fixedValues = $destination?->fixedValues() ?? [];
        $deletes = $destination === null;

        return [
            'connector.class' => 'io.confluent.connect.jdbc.JdbcSinkConnector',
            'tasks.max' => '1',
            'topics' => $this->source->topic($connection, $object),
            'table.name.format' => $destination?->table() ?? $sync->destination,
            'connection.url' => $target['url'],
            'connection.user' => $target['username'],
            'connection.password' => $target['password'],
            'pk.mode' => 'record_key',
            'insert.mode' => 'upsert',
            'delete.enabled' => $deletes ? 'true' : 'false',
            'auto.create' => 'false',
            'fields.whitelist' => implode(',', [...array_values($columns), ...array_keys($fixedValues)]),
            'transforms' => implode(',', [
                'unwrap',
                'rename_keys',
                'rename_values',
                ...array_map(
                    $this->fixedValueTransformName(...),
                    array_keys($fixedValues),
                ),
            ]),
            'transforms.unwrap.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.unwrap.delete.tombstone.handling.mode' => $deletes ? 'tombstone' : 'drop',
            'transforms.rename_keys.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
            'transforms.rename_keys.renames' => $this->renames([
                ConnectionMetadata::KEY_COLUMN => $columns[ConnectionMetadata::KEY_COLUMN],
            ]),
            'transforms.rename_values.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'transforms.rename_values.renames' => $this->renames($columns),
            ...$this->fixedValueTransforms($fixedValues),
            ...SourceConnectorConfigBuilder::CONVERTERS,
        ];
    }

    public function name(Sync $sync): string
    {
        return sprintf('ferry-sink-%s', $sync->code);
    }

    /** @return array<string, string> */
    private function columns(ConnectionMetadata $object, Sync $sync, ?StaticDestination $destination): array
    {
        $columns = $this->source->columnMap($object, $sync);

        if ($destination !== null) {
            $columns[ConnectionMetadata::KEY_COLUMN] = $destination->primaryKey();
        }

        return $columns;
    }

    private function fixedValueTransformName(string $field): string
    {
        return sprintf('insert_%s', $field);
    }

    /**
     * @param  array<string, string>  $fixedValues
     * @return array<string, string>
     */
    private function fixedValueTransforms(array $fixedValues): array
    {
        $config = [];

        foreach ($fixedValues as $field => $value) {
            $name = $this->fixedValueTransformName($field);

            $config[sprintf('transforms.%s.type', $name)] = 'org.apache.kafka.connect.transforms.InsertField$Value';
            $config[sprintf('transforms.%s.static.field', $name)] = $field;
            $config[sprintf('transforms.%s.static.value', $name)] = $value;
        }

        return $config;
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
        $name = $destination?->connection() ?? Config::get('phpinnacle-ferry.connection');
        $name = is_string($name) ? $name : Config::string('database.default');

        $targetHost = Config::get('phpinnacle-ferry.target_host');
        $host = is_string($targetHost) && $targetHost !== ''
            ? $targetHost
            : Config::string(sprintf('database.connections.%s.host', $name));
        $rawPort = Config::get(sprintf('database.connections.%s.port', $name), 5432);
        $port = is_numeric($rawPort) ? (int) $rawPort : 5432;
        $database = Config::string(sprintf('database.connections.%s.database', $name));

        return [
            'url' => sprintf('jdbc:postgresql://%s:%d/%s', $host, $port, $database),
            'username' => Config::string(sprintf('database.connections.%s.username', $name), ''),
            'password' => Config::string(sprintf('database.connections.%s.password', $name), ''),
        ];
    }
}
