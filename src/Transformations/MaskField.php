<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

final class MaskField
{
    public static function make(): Transformation
    {
        return Transformation::make('mask', TransformScope::Field)
            ->inputTypes([ColumnType::String])
            ->label(fn () => __('phpinnacle-ferry::resources.transform.mask'))
            ->icon(Heroicon::EyeSlash)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.mask_description'))
            ->schema(fn () => [
                TextInput::make('replacement')
                    ->label(__('phpinnacle-ferry::resources.transform.mask_replacement'))
                    ->default('***')
                    ->required(),
            ]);
    }
}
