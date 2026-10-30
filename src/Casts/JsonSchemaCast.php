<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Jason\Schema;

/**
 * @phpstan-import-type Definition from Schema
 * @implements CastsAttributes<Schema, Schema|Definition>
 */
class JsonSchemaCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Schema
    {
        /** @var string $value */
        return Schema::fromJson($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        /** @var Schema|Definition $value */
        return ($value instanceof Schema ? $value : Schema::fromArray($value))->toJson();
    }

    /** @return Definition */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array|bool
    {
        /** @var Schema $value */
        return $value->toArray();
    }
}
