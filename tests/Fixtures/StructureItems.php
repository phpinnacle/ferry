<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Illuminate\Support\Facades\Lang;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Services\Rosetta\StructureConverter;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Rosetta\Data\MetadataDefinition;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\SystemMapper;

/** @phpstan-import-type Definition from Schema */
class StructureItems
{
    public static function documentWithSection(
        string $id = 'document-id',
        string $name = '_document1',
        string $title = 'Orders',
        string $sectionId = 'section-id',
    ): StructureItem {
        return new StructureConverter()->item(new MetadataDefinition(
            id: $id,
            name: $name . 'x1',
            code: 1,
            kind: MetadataKind::Document,
            label: $title,
            title: $title,
            system: ['_idrref' => new ReferenceField($id)],
            properties: [],
            sections: [new MetadataDefinition(
                id: $sectionId,
                name: $name . '_vt2x1',
                code: 2,
                kind: MetadataKind::Section,
                label: 'Lines',
                title: 'Lines',
                system: new SystemMapper()->section($name, $id, '_lineno3'),
                properties: [new MetadataProperty(
                    id: 'reference-field',
                    name: '_fld5',
                    code: 5,
                    kind: PropertyKind::Field,
                    label: 'Reference',
                    title: 'Reference',
                    field: new ReferenceField('other-document'),
                )],
            )],
        ));
    }

    /**
     * @param array{fields?: array<string, Schema>, sources?: array<string, Definition>, ...} $attributes
     */
    public static function make(array $attributes = []): StructureItem
    {
        $properties = [];

        foreach ($attributes['fields'] ?? [] as $name => $field) {
            $properties[$name] = [
                ...$field->toArray(),
                'title' => $name,
                ...(
                    Lang::has('phpinnacle-ferry::resources.replica.system_fields.' . $name)
                        ? ['x-ferry-trans' => 'phpinnacle-ferry::resources.replica.system_fields.' . $name]
                        : []
                ),
            ];
        }

        $properties = [...$properties, ...($attributes['sources'] ?? [])];

        return new StructureItem([
            'primary_key' => ['_idrref'],
            'priority' => 0,
            'schema' => Schema::fromArray([
                'type' => 'object',
                'properties' => $properties,
                'x-ferry-primary' => $attributes['primary_key'] ?? ['_idrref'],
            ]),
            ...array_diff_key($attributes, array_flip(['fields', 'sources'])),
        ]);
    }
}
