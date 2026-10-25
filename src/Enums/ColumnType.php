<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Eidos\Forms\JsonSchema;
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
            'string' => match (true) {
                $schema->annotation('x-ferry-reference') === true => self::Reference,
                $schema->annotation('format') === 'date-time' => self::DateTime,
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

    /** @return array{type: string, format?: string, x-ferry-reference?: true, minLength?: int, maxLength?: int, minimum?: int, exclusiveMinimum?: int|float, exclusiveMaximum?: int|float, multipleOf?: int|float} */
    public function jsonSchema(?Field $field = null): array
    {
        $schema = match ($this) {
            self::Reference => [
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ],
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

        return $schema;
    }

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.column_type.' . $this->value);
    }

    public function canMapTo(self $target): bool
    {
        if ($this === self::Reference && $target === self::String) {
            return true;
        }

        $sourceSchema = JsonSchema::fromArray($this->jsonSchema());
        $targetSchema = JsonSchema::fromArray($target->jsonSchema());

        return (
            $sourceSchema->annotation('format') === $targetSchema->annotation('format')
            && $sourceSchema->compatible($targetSchema)
        );
    }
}
