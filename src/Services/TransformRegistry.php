<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Ferry\Contracts\Transform;

#[Singleton]
class TransformRegistry
{
    /** @var array<string, Transform> */
    private array $items = [];

    public function register(Transform ...$transforms): void
    {
        foreach ($transforms as $transform) {
            $this->items[$transform->getKey()] = $transform;
        }
    }

    public function find(string $key): ?Transform
    {
        return $this->items[$key] ?? null;
    }

    public function get(string $key): Transform
    {
        return (
            $this->find($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.replica.errors.transform_missing',
                ['transform' => $key],
            ))
        );
    }

    /** @return array<string, Transform> */
    public function all(): array
    {
        return $this->items;
    }
}
