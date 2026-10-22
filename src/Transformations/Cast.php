<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Transformation;

final class Cast
{
    public static function make(): Transformation
    {
        return Transformation::make('cast', TransformScope::Field)
            ->outputType(fn (?ColumnType $input, array $params) => ColumnType::tryFrom($params['type'] ?? ''))
            ->label(fn () => __('phpinnacle-ferry::resources.transform.cast'))
            ->description(fn () => __('phpinnacle-ferry::resources.transform.cast_description'))
            ->icon(Heroicon::ArrowPath)
            ->schema(fn () => [
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                    ->options(ColumnType::castableOptions())
                    ->required(),
            ]);
    }
}
