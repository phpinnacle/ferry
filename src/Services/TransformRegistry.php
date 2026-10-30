<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

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

    /** @param list<TransformSetting> $settings */
    public function resultSchema(
        JsonSchema $input,
        array $settings,
        ?TransformTarget $target = TransformTarget::Value,
        TransformScope $scope = TransformScope::Field,
    ): ?JsonSchema {
        foreach ($settings as $setting) {
            $transform = $this->getOrFail($setting->type);
            if (
                $transform->getScope() !== $scope
                || $target !== null
                && $transform->getTarget() !== $target
                || !$transform->accepts($input)
            ) {
                return null;
            }
            $input = $transform->output($input, $setting->data);
            if ($input === null) {
                return null;
            }
        }

        return $input;
    }

    /** @param list<TransformSetting> $settings */
    public function previewRecordSchema(JsonSchema $schema, array $settings): JsonSchema
    {
        foreach ($settings as $setting) {
            $next = $this->resultSchema($schema, [$setting], null, TransformScope::Record);
            if ($next === null) {
                break;
            }
            $schema = $next;
        }

        return $schema;
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
            $mapping->transforms(),
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
