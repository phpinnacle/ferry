<?php

namespace PHPinnacle\Ferry\Data;

use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\Fields\UnionField;

final readonly class SourceField
{
    public function __construct(
        public string $source,
        public string $physical,
        public string $title,
        public Field $field,
    ) {}

    /** @return list<self> */
    public static function fromProperty(MetadataProperty $property): array
    {
        $title = match (true) {
            $property->title !== '' => $property->title,
            $property->label !== '' => $property->label,
            default => $property->name,
        };
        $field = $property->field;
        if (!$field instanceof UnionField) {
            return [new self(
                $property->id,
                $property->name . ($field instanceof ReferenceField ? 'rref' : ''),
                $title,
                $field,
            )];
        }

        $variants = [];
        foreach (self::variants($field) as $variant) {
            $variants[$variant->type()->value] = $variant;
        }
        // Debezium's binary.handling.mode=hex exposes the one- and four-byte tags as strings.
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
                : new ScalarField(FieldType::Uuid);
        }

        $sources = [];
        foreach ($columns as $suffix => $column) {
            $sources[] = new self(
                $property->id . ':' . $suffix,
                $property->name . '_' . $suffix,
                $title . ' · ' . __('phpinnacle-ferry::resources.sync.components.' . $suffix),
                $column,
            );
        }

        return $sources;
    }

    /** @return list<Field> */
    private static function variants(Field $field): array
    {
        if (!$field instanceof UnionField) {
            return [$field];
        }

        return array_merge(...array_map(self::variants(...), $field->variants));
    }
}
