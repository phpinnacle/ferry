<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Rosetta\Contracts\Field;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 *
 * @implements CastsAttributes<array<string, Field>, array<string, Field>>
 */
class SystemCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @return array<string, Field> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var string $value */
        /** @var array<string, FieldData> $fields */
        $fields = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return array_map(FieldDecoder::decode(...), $fields);
    }

    /**
     * @param  array<string, Field>  $value
     * @return array<string, array<string, mixed>>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var array<string, array<string, mixed>> */
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, Field> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
