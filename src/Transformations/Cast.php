<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Cast
{
    public static function make(): Transformation
    {
        return Transformation::make('cast')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.cast'))
            ->description(fn () => __('phpinnacle-ferry::resources.transform.cast_description'))
            ->icon(Heroicon::ArrowPath)
            ->schema(fn (?JsonSchema $input) => [
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.constant_type'))
                    ->options([
                        'string' => __('phpinnacle-ferry::resources.transform.cast_types.string'),
                        'int8' => 'int8',
                        'int16' => 'int16',
                        'int32' => 'int32',
                        'int64' => 'int64',
                        'float32' => 'float32',
                        'float64' => 'float64',
                        'boolean' => __('phpinnacle-ferry::resources.transform.cast_types.boolean'),
                    ])
                    ->disableOptionWhen(
                        fn (string $value) => (
                            $input?->singleType() === $value
                            && $input->annotation('format') === null
                            && $input->annotation('x-ferry-reference') !== true
                        ),
                    )
                    ->required(),
                Select::make('replace_null_with_default')
                    ->label(__('phpinnacle-ferry::resources.transform.cast_replace_null_with_default'))
                    ->helperText(__('phpinnacle-ferry::resources.transform.cast_replace_null_with_default_help'))
                    ->options([
                        'true' => __('filament-forms::components.select.boolean.true'),
                        'false' => __('filament-forms::components.select.boolean.false'),
                    ])
                    ->default('true')
                    ->formatStateUsing(fn (?string $state) => $state ?? 'true')
                    ->selectablePlaceholder(false)
                    // Keep the default implicit in stored transform settings.
                    ->dehydrated(fn (?string $state) => $state === 'false'),
            ])
            ->inputSchema(['type' => ['string', 'integer', 'number', 'boolean', 'null']])
            ->outputSchema(function (JsonSchema $input, array $params) {
                if (($params['type'] ?? null) === null) {
                    return null;
                }

                $schema = ['type' => match ($params['type']) {
                    'int8', 'int16', 'int32', 'int64' => 'integer',
                    'float32', 'float64' => 'number',
                    default => $params['type'],
                }];

                if ($input->types() === [] || in_array('null', $input->types(), true)) {
                    $schema['type'] = [$schema['type'], 'null'];
                }

                return $schema;
            });
    }
}
