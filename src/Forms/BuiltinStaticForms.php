<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Illuminate\Translation\PotentiallyTranslatedString;

final class BuiltinStaticForms
{
    /**
     * @param 'string'|'integer'|'number'|'boolean'|'array'|'null' $type
     * @return ($type is 'array' ? Field|null : Field)
     */
    public static function make(JsonSchema $property, string $type, bool $required): ?Field
    {
        $definition = $property->toArray();
        $field = match ($type) {
            'string' => self::string($property),
            'integer' => TextInput::make('value')
                ->integer()
                ->step(1)
                ->minValue($definition['minimum'] ?? null)
                ->maxValue($definition['maximum'] ?? null),
            'number' => TextInput::make('value')
                ->numeric()
                ->minValue($definition['minimum'] ?? null)
                ->maxValue($definition['maximum'] ?? null),
            'boolean' => in_array('null', $property->types(), true)
                ? Select::make('value')->boolean()
                : Toggle::make('value')->default(false),
            'array' => self::array($property),
            'null' => TextInput::make('value')
                ->readOnly()
                ->placeholder('null')
                ->rules(fn () => [self::validateNull(...)]),
        };

        if ($field === null) {
            return null;
        }

        return $field
            ->label($property->annotation('title') ?? __('phpinnacle-ferry::resources.sync.fields.constant_value'))
            ->hiddenLabel()
            ->required($required && !in_array('null', $property->types(), true) && !$field instanceof Repeater);
    }

    private static function array(JsonSchema $property): ?Repeater
    {
        $items = $property->items();
        $type = $items?->singleType();
        // shortcut: omit composite items and nullable arrays until Filament can hydrate them without changing values.
        if (
            in_array('null', $property->types(), true)
            || !in_array($type, ['string', 'integer', 'number', 'boolean'], true)
        ) {
            return null;
        }

        $definition = $property->toArray();
        $item = $type === 'boolean'
            ? Select::make('value')
                ->boolean()
                ->required(!in_array('null', $items->types(), true))
            : self::make($items, $type, true);

        return Repeater::make('value')
            ->defaultItems(0)
            ->minItems($definition['minItems'] ?? null)
            ->maxItems($definition['maxItems'] ?? null)
            ->simple(
                $item
                    ->distinct(
                        ($definition['uniqueItems'] ?? false) && in_array($type, ['string', 'integer', 'number'], true),
                    ),
            );
    }

    private static function string(JsonSchema $property): Field
    {
        $definition = $property->toArray();
        if (array_key_exists('enum', $definition)) {
            $options = [];
            foreach ($definition['enum'] as $value) {
                if (is_string($value)) {
                    $options[$value] = $value;
                }
            }

            return Select::make('value')->options($options);
        }

        $field = match ($property->annotation('format')) {
            'uuid', 'ferry-reference' => TextInput::make('value')->uuid(),
            'phone' => TextInput::make('value')->tel(),
            'email' => TextInput::make('value')->email(),
            'date' => DatePicker::make('value')->format('Y-m-d'),
            'time' => TimePicker::make('value')->format('H:i:s')->rule('date_format:H:i:s'),
            'date-time' => DateTimePicker::make('value')->format('Y-m-d\TH:i:sP'),
            default => TextInput::make('value'),
        };

        if ($field instanceof TextInput) {
            $field->minLength($definition['minLength'] ?? null)->maxLength($definition['maxLength'] ?? null);
        }

        return $field;
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private static function validateNull(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null) {
            $fail('validation.prohibited')->translate();
        }
    }
}
