<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Rosetta\StorageMap;

/** @implements CastsAttributes<StorageMap|null, StorageMap|null> */
class StorageMapCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @param string|null $value */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StorageMap
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, array<string, int>> $entries */
        $entries = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return StorageMap::fromArray($entries);
    }

    /**
     * @param  StorageMap|null  $value
     * @return array<string, array<string, int>>|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value?->toArray();
    }

    /** @param StorageMap|null $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null
            ? null
            : json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
