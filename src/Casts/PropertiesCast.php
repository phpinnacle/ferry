<?php

namespace PHPinnacle\Ferry\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\PropertyKind;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 *
 * @phpstan-type PropertyData array{id: string, name: string, code: int, kind: 'field'|'reference', label: string, title: string, field: FieldData}
 *
 * @implements CastsAttributes<list<MetadataProperty>, list<MetadataProperty>>
 */
class PropertiesCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @return list<MetadataProperty> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var string $value */
        /** @var list<PropertyData> $properties */
        $properties = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return array_map($this->property(...), $properties);
    }

    /**
     * @param  list<MetadataProperty>  $value
     * @return list<array<string, mixed>>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        /** @var list<array<string, mixed>> */
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<MetadataProperty> $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @param PropertyData $data */
    private function property(array $data): MetadataProperty
    {
        return new MetadataProperty(
            id: $data['id'],
            name: $data['name'],
            code: $data['code'],
            kind: PropertyKind::from($data['kind']),
            label: $data['label'],
            title: $data['title'],
            field: FieldDecoder::decode($data['field']),
        );
    }
}
