<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use PHPinnacle\Ferry\Data\CapturePlan;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\TransformRegistry;

class SourceFactory
{
    private const string DEFAULT_SCHEMA = 'public';

    private const int IDENTIFIER_LENGTH = 63;

    public function __construct(
        private readonly TransformRegistry $transforms,
    ) {}

    /**
     * @param array{
     *     options: array<string, string>,
     *     converters: array<string, string>,
     *     signal: array{topic: string, bootstrap_servers: string, group_id: string, ...},
     *     transforms: array<string, array<string, string>>,
     *     ...
     * } $config
     *
     * @return array<string, string>
     */
    public function source(Upstream $upstream, CapturePlan $capture, array $config): array
    {
        $scope = $capture->isEmpty()
            ? ['table.include.list' => '(?!)']
            : [
                'table.include.list' => implode(',', $capture->tables),
                ...($capture->columns !== null ? ['column.include.list' => implode(',', $capture->columns)] : []),
                ...($capture->keys !== [] ? ['message.key.columns' => implode(';', $capture->keys)] : []),
            ];

        /** @var array{host: string, port: int, username: string, password: string, database: string} $connection */
        $connection = $capture->config;

        $signal = $config['signal'];
        $pipeline = $config['transforms'];

        foreach ($upstream->transforms as $index => $setting) {
            $pipeline['upstream_' . $index] = $this->transforms
                ->get($setting->type)
                ->connector(TransformTarget::Value, $setting->data);
        }

        return [
            'connector.class' => 'io.debezium.connector.postgresql.PostgresConnector',
            'tasks.max' => '1',
            'database.hostname' => $connection['host'],
            'database.port' => (string) $connection['port'],
            'database.user' => $connection['username'],
            'database.password' => $connection['password'],
            'database.dbname' => $connection['database'],
            'plugin.name' => 'pgoutput',
            'slot.name' => $this->identifier('ferry_slot', $upstream),
            'publication.name' => $this->identifier('ferry_pub', $upstream),
            'publication.autocreate.mode' => 'filtered',
            'topic.prefix' => $upstream->topicPrefix(),
            'binary.handling.mode' => 'hex',
            'include.unknown.datatypes' => 'true',
            'read.only' => 'true',
            'signal.enabled.channels' => 'kafka',
            'signal.kafka.topic' => $signal['topic'],
            'signal.kafka.bootstrap.servers' => $signal['bootstrap_servers'],
            'signal.kafka.groupId' => $signal['group_id'],
            ...$scope,
            ...(
                $capture->converterColumns === null
                    ? []
                    : self::converters($config['converters'], $capture->converterColumns)
            ),
            ...TransformFactory::configuration($pipeline),
            ...$config['options'],
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
        return $upstream->topicName($this->table($upstream, $object));
    }

    /**
     * @param array<string, string> $previousConfig
     * @param array<string, string> $config
     *
     * @return list<string>
     */
    public function newlyScopedTables(array $previousConfig, array $config): array
    {
        $tables = array_fill_keys(array_diff(
            explode(',', $config['table.include.list'] ?? ''),
            explode(',', $previousConfig['table.include.list'] ?? ''),
        ), true);
        unset($tables[''], $tables['(?!)']);

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

    /**
     * @param array<string, string> $classes
     * @param array<string, array<string, true>> $columns
     * @return array<string, string>
     */
    private static function converters(array $classes, array $columns): array
    {
        if ($classes === []) {
            return [];
        }

        $config = ['converters' => implode(',', array_keys($classes))];

        foreach ($classes as $name => $class) {
            $config[$name . '.type'] = $class;

            if (array_key_exists($name, $columns)) {
                $config[$name . '.columns'] = implode(',', array_keys($columns[$name]));
            }
        }

        return $config;
    }
}
