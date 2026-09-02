<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Rosetta\TypeMap;

/** @implements CastsAttributes<TypeMap|null, TypeMap|null> */
class TypeMapCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @param string|null $value */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TypeMap
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, list<array<mixed>>> $entries */
        $entries = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return TypeMap::fromArray($entries);
    }

    /**
     * @param  TypeMap|null  $value
     * @return array<string, list<array<mixed>>>|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value?->toArray();
    }

    /** @param TypeMap|null $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null
            ? null
            : json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
