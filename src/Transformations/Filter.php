<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;
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
            ->schema(function (?JsonSchema $input) {
                $example = match ($input?->singleType()) {
                    'string' => "@ == 'value'",
                    'integer' => '@ >= 100',
                    'number' => '@ >= 100.5',
                    'boolean' => '@ == true',
                    'array' => '@ empty false',
                    'object' => '@.price > 100',
                    'null' => '@ == null',
                    default => '@ != null',
                };

                return [
                    Group::make([
                        Select::make('mode')
                            ->label(__('phpinnacle-ferry::resources.transform.filter_mode'))
                            ->options([
                                'exclude' => __('phpinnacle-ferry::resources.transform.filter_exclude'),
                                'include' => __('phpinnacle-ferry::resources.transform.filter_include'),
                            ])
                            ->default('exclude')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('missing_or_null_behavior')
                            ->label(__('phpinnacle-ferry::resources.transform.filter_missing_or_null_behavior'))
                            ->options([
                                'include' => __('phpinnacle-ferry::resources.transform.filter_missing_include'),
                                'exclude' => __('phpinnacle-ferry::resources.transform.filter_missing_exclude'),
                                'fail' => __('phpinnacle-ferry::resources.transform.filter_missing_fail'),
                            ])
                            ->default('include')
                            ->selectablePlaceholder(false)
                            ->required(),
                    ])->columns(2)->columnSpanFull(),
                    Textarea::make('condition')
                        ->label(__('phpinnacle-ferry::resources.transform.filter_condition'))
                        ->helperText(__('phpinnacle-ferry::resources.transform.filter_condition_help', [
                            'example' => $example,
                        ]))
                        ->placeholder($example)
                        ->rows(3)
                        ->rules(['string'])
                        ->required(),
                ];
            });
    }
}
