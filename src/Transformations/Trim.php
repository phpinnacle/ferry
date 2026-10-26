<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Trim
{
    public static function make(): Transformation
    {
        return Transformation::make('trim')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.trim'))
            ->icon(Heroicon::ArrowsPointingIn)
            ->inputSchema(
                fn (JsonSchema $input) => (
                    $input->singleType() === 'string'
                    && $input->annotation('format') !== 'date-time'
                    && $input->annotation('x-ferry-reference') !== true
                ),
            )
            ->outputSchema(fn (JsonSchema $input) => [
                'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
            ]);
    }
}
