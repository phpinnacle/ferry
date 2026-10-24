<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class MaskField
{
    public static function make(): Transformation
    {
        return Transformation::make('mask')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.mask'))
            ->icon(Heroicon::EyeSlash)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.mask_description'))
            ->schema(fn () => [
                TextInput::make('replacement')
                    ->label(__('phpinnacle-ferry::resources.transform.mask_replacement'))
                    ->default('***')
                    ->required(),
            ])
            ->inputSchema(
                fn (JsonSchema $input) => $input->singleType() === 'string'
                && !in_array(ColumnType::fromJsonSchema($input), [ColumnType::DateTime, ColumnType::Reference], true),
            )
            ->outputSchema(fn (JsonSchema $input) => [
                'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
            ]);
    }
}
