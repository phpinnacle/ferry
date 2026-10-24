<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Enums\ColumnType;
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
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                    ->options(ColumnType::castableOptions())
                    ->disableOptionWhen(
                        fn (string $value) => $input !== null && ColumnType::fromJsonSchema($input)?->value === $value,
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
                    // Keep Kafka's default implicit in stored transform settings.
                    ->dehydrated(fn (?string $state) => $state === 'false'),
            ])
            ->inputSchema(['type' => ['string', 'integer', 'number', 'boolean', 'null']])
            ->outputSchema(function (JsonSchema $input, array $params) {
                $schema = ColumnType::tryFrom($params['type'] ?? '')?->jsonSchema();
                if ($schema === null) {
                    return null;
                }

                if ($input->types() === [] || in_array('null', $input->types(), true)) {
                    $schema['type'] = [$schema['type'], 'null'];
                }

                return $schema;
            });
    }
}
