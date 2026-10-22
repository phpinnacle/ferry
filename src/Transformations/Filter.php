<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Transformation;

final class Filter
{
    public static function make(): Transformation
    {
        return Transformation::make('filter', TransformScope::Record)
            ->label(fn () => __('phpinnacle-ferry::resources.transform.filter'))
            ->icon(Heroicon::Funnel)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.filter_description'))
            ->schema(fn (?ConnectionMetadata $object) => [
                Select::make('mode')
                    ->label(__('phpinnacle-ferry::resources.transform.filter_mode'))
                    ->options([
                        'exclude' => __('phpinnacle-ferry::resources.transform.filter_exclude'),
                        'include' => __('phpinnacle-ferry::resources.transform.filter_include'),
                    ])
                    ->default('exclude')
                    ->required(),
                Select::make('column')
                    ->label(__('phpinnacle-ferry::resources.transform.filter_column'))
                    ->options(array_map(
                        static fn (array $field) => sprintf('%s (%s)', $field['title'], $field['physical']),
                        self::fields($object),
                    ))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('value', null))
                    ->required(),
                Select::make('value')
                    ->key('value_flag')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->options([
                        'true' => __('phpinnacle-ferry::resources.transform.filter_true'),
                        'false' => __('phpinnacle-ferry::resources.transform.filter_false'),
                    ])
                    ->visible(fn (Get $get) => self::isFlag($object, $get->string('column', isNullable: true)))
                    ->required(),
                TextInput::make('value')
                    ->key('value_text')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->visible(fn (Get $get) => !self::isFlag($object, $get->string('column', isNullable: true)))
                    ->required(),
            ]);
    }

    private static function isFlag(?ConnectionMetadata $object, ?string $column): bool
    {
        return (self::fields($object)[$column ?? '']['type'] ?? null) === ColumnType::Boolean;
    }

    /** @return array<string, array{source: string, title: string, physical: string, type: ColumnType}> */
    private static function fields(?ConnectionMetadata $object): array
    {
        return array_column(
            array_filter(
                $object?->mappableFields(withKey: false) ?? [],
                static fn (array $field) => in_array($field['type'], ColumnType::filterable(), true),
            ),
            null,
            'physical',
        );
    }
}
