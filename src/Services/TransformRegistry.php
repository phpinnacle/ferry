<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

/**
 * @phpstan-type TransformInput array{key: string, params?: array<string, string>}
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
            $params = $row['params'] ?? [];
            $transform = $this->getOrFail($row['key']);
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
    public function forScope(TransformScope $scope): array
    {
        return array_filter($this->items, static fn (Transformation $transform) => $transform->scope() === $scope);
    }
}
