<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;

#[Singleton]
class TransformFactory
{
    public function handler(string $key): ?TransformHandler
    {
        /** @var array<string, class-string<TransformHandler>> $handlers */
        $handlers = Config::array('phpinnacle-ferry.transforms');
        $handler = $handlers[$key] ?? null;

        /** @var TransformHandler|null */
        return $handler === null ? null : app($handler);
    }

    public function handlerOrFail(string $key): TransformHandler
    {
        return (
            $this->handler($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.sync.errors.transform_missing',
                ['transform' => $key],
            ))
        );
    }

    /**
     * @param array<string, string> $fixedValues
     * @return array<string, string>
     */
    public function pipeline(
        Sync $sync,
        ConnectionMetadata $object,
        string $keyColumn = Sync::ID_COLUMN,
        bool $deletes = false,
        array $fixedValues = [],
    ): array {
        $pipeline = [
            'unwrap' => [
                'type' => 'io.debezium.transforms.ExtractNewRecordState',
                'delete.tombstone.handling.mode' => $deletes ? 'tombstone' : 'drop',
            ],
        ];
        $after = [];

        foreach ($sync->schema as $index => $mapping) {
            if ($mapping->kind === FieldKind::Static) {
                $after += self::constant('field_' . $index . '_0', $mapping);
                /** @var string $target */
                $target = $mapping->target;
                foreach ($mapping->transforms as $position => $setting) {
                    $name = sprintf('field_%d_%d', $index, $position + 1);
                    $after += $this->handlerOrFail($setting->key)->connector($name, $target, $setting->params);
                }

                continue;
            }

            /** @var string $sourceId */
            $sourceId = $mapping->source;
            /** @var string $source */
            $source = $object->physicalColumn($sourceId);

            foreach ($mapping->transforms as $position => $setting) {
                $name = sprintf('field_%d_%d', $index, $position);

                $pipeline += $this->handlerOrFail($setting->key)->connector($name, $source, $setting->params);
            }
        }

        $pipeline['rename_keys'] = [
            'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
            'renames' => self::renames([ConnectionMetadata::KEY_COLUMN => $keyColumn]),
        ];
        $pipeline['rename_values'] = [
            'type' => 'org.apache.kafka.connect.transforms.ReplaceField$Value',
            'renames' => self::renames($sync->columnMap($object, $keyColumn)),
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

        if ($mapping->sourceType() !== ColumnType::String) {
            $transforms[$name . '_cast'] = [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $target . ':' . $mapping->sourceType()?->castType(),
            ];
        }

        return $transforms;
    }

    public function appliesTo(Sync $sync): bool
    {
        foreach ($sync->schema as $mapping) {
            foreach ($mapping->transforms as $setting) {
                if ($this->handler($setting->key) === null) {
                    return false;
                }
            }
        }

        return true;
    }
}
