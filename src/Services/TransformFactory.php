<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\TransformHandler;
use PHPinnacle\Ferry\Data\StaticValue;
use PHPinnacle\Ferry\Enums\ColumnType;
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
     * @return array{before: array<string, array<string, string>>, after: array<string, array<string, string>>}
     */
    public function pipeline(Sync $sync, ConnectionMetadata $object): array
    {
        $before = [];
        $after = [];

        foreach ($sync->transforms as $index => $setting) {
            $before += $this->handlerOrFail($setting->key)->connector('record_' . $index, null, $setting->params);
        }

        foreach ($sync->schema as $index => $mapping) {
            if ($mapping->constant !== null) {
                $after += self::constant('field_' . $index . '_0', $mapping->column, $mapping->constant);

                continue;
            }

            $source = $mapping->source !== null ? $object->physicalColumn($mapping->source) : null;

            foreach ($mapping->transforms as $position => $setting) {
                $name = sprintf('field_%d_%d', $index, $position);

                $before += $this->handlerOrFail($setting->key)->connector($name, $source, $setting->params);
            }
        }

        return ['before' => $before, 'after' => $after];
    }

    /** @return array<string, array<string, string>> */
    private static function constant(string $name, string $column, StaticValue $constant): array
    {
        $transforms = [
            $name => [
                'type' => 'org.apache.kafka.connect.transforms.InsertField$Value',
                'static.field' => $column,
                'static.value' => $constant->value,
            ],
        ];

        if ($constant->type !== ColumnType::String) {
            $transforms[$name . '_cast'] = [
                'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
                'spec' => $column . ':' . $constant->type->castType(),
            ];
        }

        return $transforms;
    }

    public function appliesTo(Sync $sync, ConnectionMetadata $object): bool
    {
        foreach ($sync->transforms as $setting) {
            $transform = $this->handler($setting->key);

            if ($transform === null || $transform->normalize($setting->params, $object) !== $setting->params) {
                return false;
            }

            foreach ($transform->sourceColumns($setting->params) as $column) {
                if (!$object->hasColumn($column)) {
                    return false;
                }
            }
        }

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
