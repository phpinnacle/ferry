<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

/**
 * @phpstan-type TransformInput array{type: string, data?: array<string, string>}
 */
#[Singleton]
class TransformRegistry
{
    /** @var array<string, Transformation> */
    private array $items = [];

    public function register(Transformation ...$transforms): void
    {
        foreach ($transforms as $transform) {
            $this->items[$transform->key()] = $transform;
        }
    }

    public function get(string $key): ?Transformation
    {
        return $this->items[$key] ?? null;
    }

    public function getOrFail(string $key): Transformation
    {
        return (
            $this->get($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.sync.errors.transform_missing',
                ['transform' => $key],
            ))
        );
    }

    /** @param array<array-key, TransformInput> $settings */
    public function resultSchema(JsonSchema $input, array $settings): ?JsonSchema
    {
        foreach ($settings as $row) {
            $params = $row['data'] ?? [];
            $transform = $this->getOrFail($row['type']);
            if (!$transform->accepts($input)) {
                return null;
            }
            $input = $transform->getOutputSchema($input, $params);
            if ($input === null) {
                return null;
            }
        }

        return $input;
    }

    /** @return array<string, Transformation> */
    public function all(): array
    {
        return $this->items;
    }
}
