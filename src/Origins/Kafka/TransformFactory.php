<?php

namespace PHPinnacle\Ferry\Origins\Kafka;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Origins\Kafka\Contracts\TransformHandler;

#[Singleton]
class TransformFactory
{
    public function handler(string $key): ?TransformHandler
    {
        /** @var array<string, class-string<TransformHandler>> $handlers */
        $handlers = Config::array('phpinnacle-ferry.kafka.transforms');
        $handler = $handlers[$key] ?? null;

        /** @var TransformHandler|null */
        return $handler === null ? null : app($handler);
    }

    public function handlerOrFail(string $key): TransformHandler
    {
        return (
            $this->handler($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.replica.errors.transform_missing',
                ['transform' => $key],
            ))
        );
    }

    /**
     * @param array<string, string> $keyMap
     * @param array<string, string> $fixedValues
     * @return array<string, string>
     */
    // @mago-expect lint:excessive-parameter-list
    public function pipeline(
        Replica $replica,
        StructureItem $object,
        array $keyMap = [],
        bool $deletes = false,
        array $fixedValues = [],
        bool $unwrap = true,
    ): array {
        $pipeline = [];
        if ($unwrap) {
            $pipeline['unwrap'] = [
                'type' => 'io.debezium.transforms.ExtractNewRecordState',
                'delete.tombstone.handling.mode' => $deletes ? 'tombstone' : 'drop',
            ];
        } elseif (!$deletes) {
            $pipeline['drop_tombstones'] = [
                'type' => 'org.apache.kafka.connect.transforms.Filter',
                'predicate' => 'tombstone',
            ];
        }
        $after = [];

        foreach ($replica->schema as $index => $mapping) {
            if ($mapping->kind === FieldKind::Static) {
                $after += self::constant('field_' . $index . '_0', $mapping);
                /** @var string $target */
                $target = $mapping->target;
                foreach ($mapping->transforms as $position => $setting) {
                    $name = sprintf('field_%d_%d', $index, $position + 1);
                    $after += $this->handlerOrFail($setting->type)->connector($name, $target, $setting->data);
                }

                continue;
            }

            /** @var string $source */
            $source = $mapping->source;

            foreach ($mapping->transforms as $position => $setting) {
                $name = sprintf('field_%d_%d', $index, $position);

                $pipeline += $this->handlerOrFail($setting->type)->connector($name, $source, $setting->data);
            }
        }

        if ($keyMap !== []) {
            $pipeline['rename_keys'] = [
                'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
                'renames' => self::renames($keyMap),
            ];
        }
        $pipeline['rename_values'] = [
            'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'renames' => self::renames($replica->columnMap($object)),
        ];
        $pipeline += $after;

        foreach ($fixedValues as $field => $value) {
            $pipeline['insert_' . $field] = [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $field,
                'static.value' => $value,
            ];
        }

        return self::configuration($pipeline);
    }

    /** @param array<string, string> $columns */
    private static function renames(array $columns): string
    {
        $renames = [];
        foreach ($columns as $source => $destination) {
            $renames[] = sprintf('%s:%s', $source, $destination);
        }

        return implode(',', $renames);
    }

    /**
     * @param array<string, array<string, string>> $pipeline
     * @return array<string, string>
     */
    private static function configuration(array $pipeline): array
    {
        $config = ['transforms' => implode(',', array_keys($pipeline))];
        foreach ($pipeline as $name => $properties) {
            foreach ($properties as $property => $value) {
                $config[sprintf('transforms.%s.%s', $name, $property)] = $value;
            }
            if (($properties['predicate'] ?? null) === 'tombstone') {
                $config['predicates'] = 'tombstone';
                $config['predicates.tombstone.type'] = 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone';
            }
        }

        return $config;
    }

    /** @return array<string, array<string, string>> */
    private static function constant(string $name, FieldMapping $mapping): array
    {
        /** @var string $target */
        $target = $mapping->target;
        $transforms = [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $target,
                'static.value' => match ($mapping->static) {
                    true => 'true',
                    false => 'false',
                    default => (string) $mapping->static,
                },
            ],
        ];

        $cast = match (true) {
            is_bool($mapping->static) => 'boolean',
            is_int($mapping->static) => 'int64',
            is_float($mapping->static) => 'float64',
            default => null,
        };
        if ($cast !== null) {
            $transforms[$name . '_cast'] = [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $target . ':' . $cast,
            ];
        }

        return $transforms;
    }
}
