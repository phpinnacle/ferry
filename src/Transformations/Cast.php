<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Forms\JsonSchema;
use PHPinnacle\Ferry\Transformation;

final class Cast
{
    public static function make(): Transformation
    {
        return Transformation::make('cast')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.cast'))
            ->description(fn () => __('phpinnacle-ferry::resources.transform.cast_description'))
            ->icon(Heroicon::ArrowPath)
            ->schema(fn () => [
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_type'))
                    ->options(ColumnType::castableOptions())
                    ->required(),
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
