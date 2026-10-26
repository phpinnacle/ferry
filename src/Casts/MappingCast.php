<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Ferry\Data\FieldMapping;

/**
 * @phpstan-import-type MappingData from FieldMapping
 *
 * @implements CastsAttributes<list<FieldMapping>, list<FieldMapping>|list<MappingData>>
 */
class MappingCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @return list<FieldMapping> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var string $value */
        /** @var list<MappingData> $mappings */
        $mappings = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return array_map(FieldMapping::fromArray(...), $mappings);
    }

    /**
     * @param  list<FieldMapping>  $value
     * @return list<MappingData>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return array_map(static fn (FieldMapping $mapping) => $mapping->jsonSerialize(), $value);
    }

    /** @param list<FieldMapping>|list<MappingData> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
