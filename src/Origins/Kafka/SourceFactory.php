<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;

class SourceFactory
{
    private const string DEFAULT_SCHEMA = 'public';

    private const int IDENTIFIER_LENGTH = 63;

    /**
     * @param array{
     *     signal: array{topic: string, bootstrap_servers: string, group_id: string, ...},
     *     ...
     * } $config
     *
     * @return array<string, string>
     */
    public function source(Upstream $upstream, array $config): array
    {
        $replicas = $upstream->replicas()->get();
        $tables = [];
        $columns = [];
        $keys = [];

        $objects = $upstream
            ->structure
            ->publishedItems()
            ->whereIn('external_id', $replicas->pluck('source')->all())
            ->get()
            ->keyBy('external_id');

        foreach ($replicas as $replica) {
            $object = $objects->get($replica->source);

            if (!$object instanceof StructureItem) {
                continue;
            }

            $table = $this->table($upstream, $object);

            $tables[$table] = true;

            if ($object->primary_key !== []) {
                $keys[$table] = sprintf('%s:%s', $table, implode(',', $object->primary_key));
            }

            foreach ([...$object->primary_key, ...array_keys($replica->columnMap($object))] as $column) {
                $columns[sprintf('%s.%s', $table, $column)] = true;
            }

            foreach ($replica->schema as $mapping) {
                if ($mapping->source !== null && $mapping->isUsed()) {
                    if ($object->field($mapping->source) !== null) {
                        $columns[sprintf('%s.%s', $table, $mapping->source)] = true;
                    }
                }
            }
        }

        $scope = $tables !== []
            ? [
                'table.include.list' => implode(',', array_keys($tables)),
                'column.include.list' => implode(',', array_keys($columns)),
                ...($keys !== [] ? ['message.key.columns' => implode(';', $keys)] : []),
            ]
            : ['table.include.list' => '(?!)'];

        /** @var array{host: string, port: int, username: string, password: string, database: string} $credentials */
        $credentials = $upstream->config;

        $signal = $config['signal'];

        return [
            'connector.class' => 'io.debezium.connector.postgresql.PostgresConnector',
            'tasks.max' => '1',
            'database.hostname' => $credentials['host'],
            'database.port' => (string) $credentials['port'],
            'database.user' => $credentials['username'],
            'database.password' => $credentials['password'],
            'database.dbname' => $credentials['database'],
            'plugin.name' => 'pgoutput',
            'slot.name' => $this->identifier('ferry_slot', $upstream),
            'publication.name' => $this->identifier('ferry_pub', $upstream),
            'publication.autocreate.mode' => 'filtered',
            'topic.prefix' => $this->topicPrefix($upstream),
            'binary.handling.mode' => 'hex',
            'read.only' => 'true',
            'signal.enabled.channels' => 'kafka',
            'signal.kafka.topic' => $signal['topic'],
            'signal.kafka.bootstrap.servers' => $signal['bootstrap_servers'],
            'signal.kafka.groupId' => $signal['group_id'],
            ...$scope,
            'transforms' => 'unwrap',
            'transforms.unwrap.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.unwrap.delete.tombstone.handling.mode' => 'tombstone',
        ];
    }

    public function name(Upstream|Replica $owner): string
    {
        return sprintf('ferry-%s-%s', $owner instanceof Upstream ? 'source' : 'sink', $owner->code);
    }

    public function table(Upstream $upstream, StructureItem $object): string
    {
        /** @var array{schema?: string|null} $config */
        $config = $upstream->config;

        return sprintf('%s.%s', $config['schema'] ?? self::DEFAULT_SCHEMA, $object->name);
    }

    public function topic(Upstream $upstream, StructureItem $object): string
    {
        return sprintf('%s.%s', $this->topicPrefix($upstream), $this->table($upstream, $object));
    }

    /**
     * @param array<string, string> $previousConfig
     * @param array<string, string> $config
     *
     * @return list<string>
     */
    public function newlyScopedTables(array $previousConfig, array $config): array
    {
        $tables = [];

        foreach (array_diff($this->columns($config), $this->columns($previousConfig)) as $column) {
            $parts = explode('.', $column);
            array_pop($parts);

            $tables[implode('.', $parts)] = true;
        }

        return array_keys($tables);
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

    private function identifier(string $prefix, Upstream $upstream): string
    {
        $code = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($upstream->code)) ?? '';

        return mb_substr(sprintf('%s_%s', $prefix, trim($code, '_')), 0, self::IDENTIFIER_LENGTH);
    }

    private function topicPrefix(Upstream $upstream): string
    {
        return sprintf('ferry.%s', $upstream->code);
    }
}
