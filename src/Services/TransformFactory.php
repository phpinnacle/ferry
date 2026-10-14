<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Validator;
use LogicException;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;

/**
 * @phpstan-type TransformInput array{key?: string|null, params?: array<string, string>}
 */
#[Singleton]
class TransformFactory
{
    /** @var array<string, Transform> */
    private array $items = [];

    public function register(Transform ...$transforms): void
    {
        foreach ($transforms as $transform) {
            $this->items[$transform->key] = $transform;
        }
    }

    public function get(string $key): ?Transform
    {
        return $this->items[$key] ?? null;
    }

    public function getOrFail(string $key): Transform
    {
        return (
            $this->get($key) ?? throw new LogicException(__(
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
            $before += $this->getOrFail($setting->key)->connector('record_' . $index, null, $setting->params);
        }

        foreach ($sync->schema as $index => $mapping) {
            $source = $mapping->source !== null ? $object->physicalColumn($mapping->source) : null;

            foreach ($mapping->transforms as $position => $setting) {
                $name = sprintf('field_%d_%d', $index, $position);

                if ($source === null) {
                    $after += $this->getOrFail($setting->key)->connector($name, $mapping->column, $setting->params);

                    continue;
                }

                $before += $this->getOrFail($setting->key)->connector($name, $source, $setting->params);
            }
        }

        return ['before' => $before, 'after' => $after];
    }

    public function appliesTo(Sync $sync, ConnectionMetadata $object): bool
    {
        foreach ($sync->transforms as $setting) {
            $transform = $this->get($setting->key);

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
                if ($this->get($setting->key) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $types
     * @param array<string, array<array-key, TransformInput>> $fieldTransforms
     * @return array<string, string>
     */
    public function resultTypes(array $types, array $fieldTransforms): array
    {
        foreach ($fieldTransforms as $source => $rows) {
            foreach ($rows as $row) {
                $params = $row['params'] ?? [];
                $transform = $this->get($row['key'] ?? '');

                if (
                    !array_key_exists($source, $types)
                    || $transform === null
                    || Validator::make($params, $transform->rules($params))->fails()
                ) {
                    continue;
                }

                $type = $transform->resultType(ColumnType::from($types[$source]), $params);

                if ($type === null) {
                    unset($types[$source]);

                    continue;
                }

                $types[$source] = $type->value;
            }
        }

        return $types;
    }

    /** @return array<string, Transform> */
    public function forScope(TransformScope $scope): array
    {
        return array_filter($this->items, static fn (Transform $transform) => $transform->scope === $scope);
    }
}
