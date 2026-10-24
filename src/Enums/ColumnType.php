<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Forms\JsonSchema;
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

    public static function fromValue(string|int|float|bool $value): self
    {
        return match (true) {
            is_bool($value) => self::Boolean,
            is_int($value) => self::Integer,
            is_float($value) => self::Decimal,
            default => self::String,
        };
    }

    public static function fromJsonSchema(JsonSchema $schema): ?self
    {
        return match ($schema->singleType()) {
            'string' => match ($schema->annotation('format')) {
                'ferry-reference' => self::Reference,
                'date-time' => self::DateTime,
                default => self::String,
            },
            'integer' => self::Integer,
            'number' => self::Decimal,
            'boolean' => self::Boolean,
            default => null,
        };
    }

    /** @return list<self> */
    public static function castable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type) => $type->castType() !== null));
    }

    /** @return array<string, string> */
    public static function castableOptions(): array
    {
        $options = [];

        foreach (self::castable() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        return $options;
    }

    public function valueRule(): string
    {
        return match ($this) {
            self::Integer => 'integer',
            self::Decimal => 'numeric',
            self::Boolean => 'in:true,false',
            default => 'string',
        };
    }

    public function castType(): ?string
    {
        return match ($this) {
            self::String => 'string',
            self::Integer => 'int64',
            self::Decimal => 'float64',
            self::Boolean => 'boolean',
            self::Reference, self::DateTime => null,
        };
    }

    /** @return array{type: string, format?: string, minLength?: int, maxLength?: int, minimum?: int, description?: string} */
    public function jsonSchema(?Field $field = null): array
    {
        $schema = match ($this) {
            self::Reference => ['type' => 'string', 'format' => 'ferry-reference'],
            self::DateTime => ['type' => 'string', 'format' => 'date-time'],
            self::Decimal => ['type' => 'number'],
            default => ['type' => $this->value],
        };

        if ($field instanceof StringField && $field->length !== null && $field->length > 0) {
            $schema['maxLength'] = $field->length;
            if ($field->fixed) {
                $schema['minLength'] = $field->length;
            }
        }
        if ($field instanceof NumberField) {
            if ($field->unsigned) {
                $schema['minimum'] = 0;
            }
            $description = self::numberDescription($field);
            if ($description !== '') {
                $schema['description'] = $description;
            }
        }

        return $schema;
    }

    private static function numberDescription(NumberField $field): string
    {
        $parts = [];
        foreach (['precision' => $field->precision, 'scale' => $field->scale] as $kind => $value) {
            if ($value !== null) {
                $parts[] = __('phpinnacle-ferry::resources.mapping.constraints.' . $kind) . ': ' . $value;
            }
        }
        if ($field->precision !== null && $field->precision > 0 && $field->scale !== null) {
            $whole = str_repeat('9', $field->precision - $field->scale);
            $maximum = ($whole === '' ? '0' : $whole) . ($field->scale > 0 ? '.' . str_repeat('9', $field->scale) : '');
            $minimum = $field->unsigned ? '0' : '-' . $maximum;
            $parts[] = __('phpinnacle-ferry::resources.mapping.constraints.range') . ': ' . $minimum . ' … ' . $maximum;
        }

        return implode('; ', $parts);
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.column_type.' . $this->value);
    }

    public function canMapTo(self $target): bool
    {
        $sourceSchema = JsonSchema::fromArray($this->jsonSchema());
        $targetSchema = JsonSchema::fromArray($target->jsonSchema());

        return (
            $sourceSchema->annotation('format') === $targetSchema->annotation('format')
            && $sourceSchema->compatible($targetSchema)
        );
    }
}
