<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

final class Substring
{
    public static function make(): Transformation
    {
        return Transformation::make('substring', TransformScope::Field)
            ->inputTypes([ColumnType::String])
            ->label(fn () => __('phpinnacle-ferry::resources.transform.substring'))
            ->icon(Heroicon::Scissors)
            ->schema(fn () => [
                Grid::make(2)
                    ->schema([
                        TextInput::make('start')
                            ->label(__('phpinnacle-ferry::resources.transform.substring_start'))
                            ->helperText(__('phpinnacle-ferry::resources.transform.substring_start_help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2_147_483_647)
                            ->default('0')
                            ->required()
                            ->dehydrateStateUsing(fn (int|string $state) => (string) $state),
                        TextInput::make('end')
                            ->label(__('phpinnacle-ferry::resources.transform.substring_end'))
                            ->helperText(__('phpinnacle-ferry::resources.transform.substring_end_help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2_147_483_647)
                            ->gte('start')
                            ->dehydrated(fn (int|string|null $state) => filled($state))
                            ->dehydrateStateUsing(fn (int|string $state) => (string) $state),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
