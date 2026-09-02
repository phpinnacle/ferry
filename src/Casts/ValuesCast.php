<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Rosetta\Data\EnumerationValue;

/**
 * @phpstan-type ValueData array{id: string, label: string, title: string}
 *
 * @implements CastsAttributes<list<EnumerationValue>, list<EnumerationValue>>
 */
class ValuesCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @return list<EnumerationValue> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var string $value */
        /** @var list<ValueData> $values */
        $values = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return array_map(
            fn (array $item) => new EnumerationValue(
                id: $item['id'],
                label: $item['label'],
                title: $item['title'],
            ),
            $values,
        );
    }

    /**
     * @param  list<EnumerationValue>  $value
     * @return list<array{id: string, label: string, title: string}>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var list<array{id: string, label: string, title: string}> */
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<EnumerationValue> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
