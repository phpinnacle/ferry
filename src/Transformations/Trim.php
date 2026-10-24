<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Trim
{
    public static function make(): Transformation
    {
        return Transformation::make('trim')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.trim'))
            ->icon(Heroicon::ArrowsPointingIn)
            ->inputSchema(
                fn (JsonSchema $input) => $input->singleType() === 'string'
                && !in_array(ColumnType::fromJsonSchema($input), [ColumnType::DateTime, ColumnType::Reference], true),
            )
            ->outputSchema(fn (JsonSchema $input) => [
                'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
            ]);
    }
}
