<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformTarget;

/**
 * @phpstan-type TransformInput array{type: string, data?: array<string, string>}
 */
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

    public function get(string $key): ?Transform
    {
        return $this->items[$key] ?? null;
    }

    public function getOrFail(string $key): Transform
    {
        return (
            $this->get($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.replica.errors.transform_missing',
                ['transform' => $key],
            ))
        );
    }

    /** @param array<array-key, TransformInput> $settings */
    public function resultSchema(
        JsonSchema $input,
        array $settings,
        TransformTarget $target = TransformTarget::Value,
    ): ?JsonSchema {
        foreach ($settings as $row) {
            $params = $row['data'] ?? [];
            $transform = $this->getOrFail($row['type']);
            if ($transform->getTarget() !== $target || !$transform->accepts($input)) {
                return null;
            }
            $input = $transform->output($input, $params);
            if ($input === null) {
                return null;
            }
        }

        return $input;
    }

    public function mappingSchema(FieldMapping $mapping, JsonSchema $schema): ?JsonSchema
    {
        /** @var string $source */
        $source = $mapping->source;
        $input = $mapping->kind === FieldKind::Static
            ? $mapping->staticSchema()
            : $schema->properties()[$source];
        /** @var list<string> $primaryKey */
        $primaryKey = $schema->annotation('x-primary-key') ?? [];

        return $this->resultSchema(
            $input,
            array_map(static fn ($setting) => $setting->jsonSerialize(), $mapping->transforms()),
            in_array($source, $primaryKey, true)
                ? TransformTarget::Key
                : TransformTarget::Value,
        );
    }

    /** @return array<string, Transform> */
    public function all(): array
    {
        return $this->items;
    }
}
