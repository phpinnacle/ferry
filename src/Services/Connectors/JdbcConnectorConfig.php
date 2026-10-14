<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\ConnectionMetadata;

final readonly class JdbcConnectorConfig
{
    /**
     * @param array<string, string> $columns
     * @param array<string, string> $fixedValues
     * @param array<string, array<string, string>> $before
     * @param array<string, array<string, string>> $after
     * @param list<string> $constants
     */
    public function __construct(
        private string $table,
        private string $topic,
        private array $columns,
        private ?string $connection = null,
        private array $fixedValues = [],
        private bool $deletes = false,
        private array $before = [],
        private array $after = [],
        private array $constants = [],
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        $target = $this->targetConnection();
        $transforms = [
            'unwrap',
            ...array_keys($this->before),
            'rename_keys',
            'rename_values',
            ...array_keys($this->after),
        ];
        $fixedTransforms = [];

        foreach ($this->fixedValues as $field => $value) {
            $name = 'insert_' . $field;
            $transforms[] = $name;
            $fixedTransforms['transforms.' . $name . '.type'] = 'org.apache.kafka.connect.transforms.InsertField$Value';
            $fixedTransforms['transforms.' . $name . '.static.field'] = $field;
            $fixedTransforms['transforms.' . $name . '.static.value'] = $value;
        }

        return [
            'connector.class' => 'io.confluent.connect.jdbc.JdbcSinkConnector',
            'tasks.max' => '1',
            'topics' => $this->topic,
            'table.name.format' => $this->table,
            'connection.url' => $target['url'],
            'connection.user' => $target['username'],
            'connection.password' => $target['password'],
            'pk.mode' => 'record_key',
            'insert.mode' => 'upsert',
            'delete.enabled' => $this->deletes ? 'true' : 'false',
            'auto.create' => 'false',
            'fields.whitelist' => implode(',', [
                ...array_values($this->columns),
                ...$this->constants,
                ...array_keys($this->fixedValues),
            ]),
            'transforms' => implode(',', $transforms),
            'transforms.unwrap.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.unwrap.delete.tombstone.handling.mode' => $this->deletes ? 'tombstone' : 'drop',
            'transforms.rename_keys.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
            'transforms.rename_keys.renames' => $this->renames([
                ConnectionMetadata::KEY_COLUMN => $this->columns[ConnectionMetadata::KEY_COLUMN],
            ]),
            'transforms.rename_values.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'transforms.rename_values.renames' => $this->renames($this->columns),
            ...$this->predicates(),
            ...$this->flatten($this->before),
            ...$this->flatten($this->after),
            ...$fixedTransforms,
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'true',
        ];
    }

    /** @return array<string, string> */
    private function predicates(): array
    {
        foreach ([...$this->before, ...$this->after] as $properties) {
            if (($properties['predicate'] ?? null) === 'tombstone') {
                return [
                    'predicates' => 'tombstone',
                    'predicates.tombstone.type' => 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone',
                ];
            }
        }

        return [];
    }

    /**
     * @param array<string, array<string, string>> $transforms
     * @return array<string, string>
     */
    private function flatten(array $transforms): array
    {
        $config = [];

        foreach ($transforms as $name => $properties) {
            foreach ($properties as $property => $value) {
                $config[sprintf('transforms.%s.%s', $name, $property)] = $value;
            }
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
    private function targetConnection(): array
    {
        /** @var string $name */
        $name = $this->connection ?? Config::get('phpinnacle-ferry.connection') ?? Config::string('database.default');
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
}
