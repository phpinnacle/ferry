<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Eidos\Forms\JsonSchema;

/** @implements CastsAttributes<JsonSchema, JsonSchema> */
class JsonSchemaCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): JsonSchema
    {
        /** @var string $value */
        /** @var array<string, mixed> $definition */
        $definition = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return JsonSchema::fromArray($definition);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        /** @var JsonSchema $value */
        return json_encode($value->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var JsonSchema $value */
        return $value->toArray();
    }
}
