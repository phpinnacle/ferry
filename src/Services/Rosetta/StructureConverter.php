<?php

namespace PHPinnacle\Ferry\Services\Rosetta;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Lang;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Data\MetadataDefinition;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;

/** @phpstan-import-type Definition from JsonSchema */
class StructureConverter
{
    public function item(MetadataDefinition $definition): StructureItem
    {
        $properties = [];
        foreach ($definition->system as $name => $field) {
            $translation = 'phpinnacle-ferry::resources.replica.system_fields.' . $name;
            $properties[$name] = [
                ...$this->field($field)->toArray(),
                'title' => $name,
                ...(Lang::has($translation) ? ['x-ferry-trans' => $translation] : []),
            ];
        }
        foreach ($definition->properties as $property) {
            foreach ($this->propertyFields($property) as $name => $field) {
                $properties[$name] = $field;
            }
        }

        $item = new StructureItem([
            'external_id' => $definition->id,
            'reference_id' => $definition->referenceId,
            'name' => $definition->name,
            'code' => $definition->code,
            'kind' => $definition->kind->value,
            'label' => $definition->label,
            'title' => $definition->title,
            'key_column' => '_idrref',
            'schema' => JsonSchema::fromArray(['type' => 'object', 'properties' => $properties]),
            'values' => array_map(static fn ($value) => $value->jsonSerialize(), $definition->values),
        ]);
        $item->priority = match (true) {
            str_starts_with($item->displayTitle(), '(') => 2,
            $definition->kind === MetadataKind::Reference => 0,
            default => 1,
        };

        return $item->setRelation('children', new Collection(array_map($this->item(...), $definition->sections)));
    }

    public function field(Field $field): JsonSchema
    {
        $schema = match (true) {
            $field instanceof StringField => ['type' => 'string'],
            $field instanceof ReferenceField => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ],
            $field instanceof NumberField => ['type' => ($field->scale ?? 0) > 0 ? 'number' : 'integer'],
            $field instanceof ScalarField => match ($field->type()) {
                FieldType::Boolean => ['type' => 'boolean'],
                FieldType::Date, FieldType::Time, FieldType::DateTime => ['type' => 'string', 'format' => 'date-time'],
                FieldType::Id => [
                    'type' => 'string',
                    'format' => 'uuid',
                    'x-ferry-reference' => true,
                    'minLength' => 36,
                    'maxLength' => 36,
                ],
                FieldType::Uuid => ['type' => 'string', 'format' => 'uuid', 'minLength' => 36, 'maxLength' => 36],
                default => [],
            },
            default => [],
        };
        if ($field instanceof StringField && $field->length !== null && $field->length > 0) {
            $schema['maxLength'] = $field->length;
            if ($field->fixed) {
                $schema['minLength'] = $field->length;
            }
        }
        if ($field instanceof NumberField) {
            if ($field->precision !== null) {
                $schema['x-ferry-precision'] = $field->precision;
            }
            if ($field->scale !== null) {
                $schema['x-ferry-scale'] = $field->scale;
            }
            if ($field->unsigned) {
                $schema['minimum'] = 0;
            }
            if ($field->scale !== null && $field->scale > 0) {
                $schema['multipleOf'] = 10 ** (-$field->scale);
            }
            if ($field->precision !== null && $field->precision > 0 && $field->scale !== null) {
                $limit = 10 ** ($field->precision - $field->scale);
                if (!$field->unsigned) {
                    $schema['exclusiveMinimum'] = -$limit;
                }
                $schema['exclusiveMaximum'] = $limit;
            }
        }

        return JsonSchema::fromArray($schema);
    }

    /** @return array<string, Definition> */
    private function propertyFields(MetadataProperty $property): array
    {
        $title = match (true) {
            $property->title !== '' => $property->title,
            $property->label !== '' => $property->label,
            default => $property->name,
        };
        $field = $property->field;
        if (!$field instanceof UnionField) {
            return [
                $property->name . ($field instanceof ReferenceField ? 'rref' : '') => [
                    ...$this->field($field)->toArray(),
                    'title' => $title,
                    ...($property->comment === null ? [] : ['description' => $property->comment]),
                ],
            ];
        }

        $variants = [];
        foreach ($this->variants($field) as $variant) {
            $variants[$variant->type()->value] = $variant;
        }
        // Represent one- and four-byte discriminator codes as fixed-length hexadecimal strings.
        $columns = ['type' => new StringField(2, true)];
        foreach ([
            'l' => FieldType::Boolean,
            'n' => FieldType::Number,
            't' => FieldType::DateTime,
            's' => FieldType::String,
        ] as $suffix => $type) {
            $variant = $variants[$type->value] ?? null;
            if ($suffix === 't') {
                $variant ??= $variants[FieldType::Date->value] ?? $variants[FieldType::Time->value] ?? null;
            }
            if ($variant !== null) {
                $columns[$suffix] = $variant;
            }
        }
        $targets = $field->targets();
        if (count($targets) > 1) {
            $columns['rtref'] = new StringField(8, true);
        }
        if ($targets !== []) {
            $columns['rrref'] = count($targets) === 1
                ? new ReferenceField($targets[0])
                : new ScalarField(FieldType::Id);
        }

        $sources = [];
        foreach ($columns as $suffix => $column) {
            $sources[$property->name . '_' . $suffix] = [
                ...$this->field($column)->toArray(),
                'title' => $title . ' · ' . __('phpinnacle-ferry::resources.replica.components.' . $suffix),
                ...($property->comment === null ? [] : ['description' => $property->comment]),
            ];
        }

        return $sources;
    }

    /** @return list<Field> */
    private function variants(Field $field): array
    {
        if (!$field instanceof UnionField) {
            return [$field];
        }

        return array_merge(...array_map($this->variants(...), $field->variants));
    }
}
