<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Ferry\Data\FieldMapping;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 *
 * @phpstan-type MappingData array{source: string, column: string, field: FieldData}
 *
 * @implements CastsAttributes<list<FieldMapping>, list<FieldMapping>>
 */
class SchemaCast implements CastsAttributes, SerializesCastableAttributes
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
     * @return list<array<string, mixed>>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var list<array<string, mixed>> */
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<FieldMapping> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
