<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Substring
{
    public static function make(): Transformation
    {
        return Transformation::make('substring')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.substring'))
            ->icon(Heroicon::Scissors)
            ->schema(fn () => [
                Group::make()
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
            ])
            ->inputSchema(
                fn (JsonSchema $input) => $input->singleType() === 'string'
                && !in_array($input->annotation('format'), ['date-time', 'ferry-reference'], true),
            )
            ->outputSchema(fn (JsonSchema $input) => [
                'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
            ]);
    }
}
