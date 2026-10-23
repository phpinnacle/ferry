<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Filter
{
    public static function make(): Transformation
    {
        return Transformation::make('filter')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.filter'))
            ->icon(Heroicon::Funnel)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.filter_description'))
            ->inputSchema(
                fn (JsonSchema $input) => (
                    in_array(
                        array_values(array_diff($input->types(), ['null'])),
                        [['string'], ['boolean']],
                        true,
                    )
                    && $input->annotation('format') !== 'date-time'
                ),
            )
            ->schema(fn (?JsonSchema $input) => [
                Hidden::make('type')
                    ->dehydrateStateUsing(fn () => in_array('boolean', $input?->types() ?? [], true)
                        ? 'boolean'
                        : 'string'),
                Select::make('mode')
                    ->label(__('phpinnacle-ferry::resources.transform.filter_mode'))
                    ->options([
                        'exclude' => __('phpinnacle-ferry::resources.transform.filter_exclude'),
                        'include' => __('phpinnacle-ferry::resources.transform.filter_include'),
                    ])
                    ->default('exclude')
                    ->required(),
                ...(
                    in_array('boolean', $input?->types() ?? [], true)
                        ? [Select::make('value')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                            ->options([
                                'true' => __('phpinnacle-ferry::resources.transform.filter_true'),
                                'false' => __('phpinnacle-ferry::resources.transform.filter_false'),
                            ])
                            ->required()]
                        : [TextInput::make('value')
                            ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                            ->required()]
                ),
            ]);
    }
}
