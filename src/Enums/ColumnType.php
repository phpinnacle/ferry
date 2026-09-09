<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ReferenceField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

enum ColumnType: string implements HasLabel
{
    case String = 'string';
    case Reference = 'reference';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case DateTime = 'datetime';

    public static function fromField(Field $field): ?self
    {
        return match (true) {
            $field instanceof StringField => self::String,
            $field instanceof ReferenceField => self::Reference,
            $field instanceof NumberField => ($field->scale ?? 0) > 0 ? self::Decimal : self::Integer,
            $field instanceof ScalarField => match ($field->type()) {
                FieldType::Boolean => self::Boolean,
                FieldType::Date, FieldType::Time, FieldType::DateTime => self::DateTime,
                FieldType::Id, FieldType::Uuid => self::Reference,
                default => null,
            },
            default => null,
        };
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.column_type.' . $this->value);
    }
}
