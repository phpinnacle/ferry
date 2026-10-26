<?php

namespace PHPinnacle\Ferry\Tests\Fixtures;

use Illuminate\Support\Facades\Lang;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\StructureItem;

/** @phpstan-import-type Definition from JsonSchema */
class StructureItems
{
    /**
     * @param array{fields?: array<string, JsonSchema>, sources?: array<string, Definition>, ...} $attributes
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
            'schema' => JsonSchema::fromArray([
                'type' => 'object',
                'properties' => $properties,
                'x-primary-key' => $attributes['primary_key'] ?? ['_idrref'],
            ]),
            ...array_diff_key($attributes, array_flip(['fields', 'sources'])),
        ]);
    }
}
