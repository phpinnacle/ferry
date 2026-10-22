<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Trim
{
    public static function make(): Transformation
    {
        return Transformation::make('trim', TransformScope::Field)
            ->label(fn () => __('phpinnacle-ferry::resources.transform.trim'))
            ->icon(Heroicon::ArrowsPointingIn)
            ->inputSchema(
                fn (JsonSchema $input) => (
                    array_values(array_diff($input->types(), ['null'])) === ['string']
                    && !in_array($input->annotation('format'), ['date-time', 'ferry-reference'], true)
                ),
            )
            ->outputSchema(fn (JsonSchema $input) => [
                'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
            ]);
    }
}
