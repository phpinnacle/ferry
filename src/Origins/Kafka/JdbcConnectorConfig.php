<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use Illuminate\Support\Facades\Config;

final readonly class JdbcConnectorConfig
{
    /**
     * @param list<string> $columns
     * @param array<string, string> $transforms
     * @param non-empty-list<string>|null $primaryKey
     */
    public function __construct(
        private string $table,
        private string $topic,
        private array $columns,
        private array $transforms,
        private ?string $connection = null,
        private bool $deletes = false,
        private ?array $primaryKey = null,
        private ?string $targetHost = null,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        $target = $this->targetConnection();

        return [
            'connector.class' => 'io.confluent.connect.jdbc.JdbcSinkConnector',
            'tasks.max' => '1',
            'topics' => $this->topic,
            'table.name.format' => $this->table,
            'connection.url' => $target['url'],
            'connection.user' => $target['username'],
            'connection.password' => $target['password'],
            'pk.mode' => $this->primaryKey !== null ? 'record_value' : 'record_key',
            ...($this->primaryKey !== null ? ['pk.fields' => implode(',', $this->primaryKey)] : []),
            'insert.mode' => 'upsert',
            'delete.enabled' => $this->deletes ? 'true' : 'false',
            'auto.create' => 'false',
            'fields.whitelist' => implode(',', $this->columns),
            ...$this->transforms,
        ];
    }

    /** @return array{url: string, username: string, password: string} */
    private function targetConnection(): array
    {
        /** @var string $name */
        $name = $this->connection ?? Config::get('phpinnacle-ferry.connection') ?? Config::string('database.default');
        $host =
            $this->targetHost !== null && $this->targetHost !== ''
                ? $this->targetHost
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
