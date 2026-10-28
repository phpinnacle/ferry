<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Ferry\Data\TransformSetting;

/**
 * @implements CastsAttributes<list<TransformSetting>, list<TransformSetting>>
 */
class TransformsCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @return list<TransformSetting> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var string $value */
        /** @var list<array{type: string, data: array<string, mixed>}> $settings */
        $settings = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return array_map(TransformSetting::fromArray(...), $settings);
    }

    /**
     * @param list<TransformSetting> $value
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return array_map(static fn (TransformSetting $setting) => $setting->jsonSerialize(), $value);
    }

    /** @param list<TransformSetting> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
