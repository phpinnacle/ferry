<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

final class Trim
{
    public static function make(): Transformation
    {
        return Transformation::make('trim', TransformScope::Field)
            ->inputTypes([ColumnType::String])
            ->label(fn () => __('phpinnacle-ferry::resources.transform.trim'))
            ->icon(Heroicon::ArrowsPointingIn);
    }
}
