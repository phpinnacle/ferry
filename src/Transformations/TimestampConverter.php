<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class TimestampConverter implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'timestamp_converter';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Field;
    }

    public function sourceFields(array $params): array
    {
        return [];
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Value;
    }

    public function accepts(JsonSchema $input): bool
    {
        return (
            $input->annotation('x-ferry-reference') !== true
            && $input->compatible(JsonSchema::fromArray(['type' => ['string', 'integer', 'null']]))
        );
    }

    /** @param array<string, string> $params */
    public function output(JsonSchema $input, array $params): ?JsonSchema
    {
        if (($params['target_type'] ?? null) === null) {
            return null;
        }

        /** @var 'string'|'unix'|'Date'|'Time'|'Timestamp' $target */
        $target = $params['target_type'];
        $schema = match ($target) {
            'string' => ['type' => 'string'],
            'unix' => ['type' => 'integer'],
            'Date' => ['type' => 'string', 'format' => 'date'],
            'Time' => ['type' => 'string', 'format' => 'time'],
            'Timestamp' => ['type' => 'string', 'format' => 'date-time'],
        };

        if ($input->types() === [] || in_array('null', $input->types(), true)) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return JsonSchema::fromArray($schema);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.timestamp_converter'))
            ->description(fn () => __('phpinnacle-ferry::resources.transform.timestamp_converter_description'))
            ->icon(Heroicon::Clock)
            ->schema(function (?JsonSchema $input) {
                $stringInput =
                    in_array('string', $input?->types() ?? [], true)
                    && !in_array($input->format(), ['date', 'time', 'date-time'], true);

                return [
                    Select::make('target_type')
                        ->label(__('phpinnacle-ferry::resources.transform.timestamp_target_type'))
                        ->options([
                            'string' => __('phpinnacle-ferry::resources.transform.timestamp_string'),
                            'unix' => __('phpinnacle-ferry::resources.transform.timestamp_unix'),
                            'Date' => __('phpinnacle-ferry::resources.transform.timestamp_date'),
                            'Time' => __('phpinnacle-ferry::resources.transform.timestamp_time'),
                            'Timestamp' => __('phpinnacle-ferry::resources.transform.timestamp_timestamp'),
                        ])
                        ->live()
                        ->required(),
                    TextInput::make('format')
                        ->label(__('phpinnacle-ferry::resources.transform.timestamp_format'))
                        ->helperText(__('phpinnacle-ferry::resources.transform.timestamp_format_help'))
                        ->placeholder("yyyy-MM-dd'T'HH:mm:ss.SSSXXX")
                        ->rules(['string'])
                        ->required(fn (Get $get) => $stringInput || $get('target_type') === 'string')
                        ->dehydrated(fn (mixed $state) => filled($state)),
                    Select::make('unix_precision')
                        ->label(__('phpinnacle-ferry::resources.transform.timestamp_unix_precision'))
                        ->helperText(__('phpinnacle-ferry::resources.transform.timestamp_unix_precision_help'))
                        ->options([
                            'seconds' => __('phpinnacle-ferry::resources.transform.timestamp_seconds'),
                            'milliseconds' => __('phpinnacle-ferry::resources.transform.timestamp_milliseconds'),
                            'microseconds' => __('phpinnacle-ferry::resources.transform.timestamp_microseconds'),
                            'nanoseconds' => __('phpinnacle-ferry::resources.transform.timestamp_nanoseconds'),
                        ])
                        ->default('milliseconds')
                        ->formatStateUsing(fn (mixed $state) => $state ?? 'milliseconds')
                        ->selectablePlaceholder(false)
                        ->required(),
                    Select::make('replace_null_with_default')
                        ->label(__('phpinnacle-ferry::resources.transform.timestamp_replace_null_with_default'))
                        ->helperText(__(
                            'phpinnacle-ferry::resources.transform.timestamp_replace_null_with_default_help',
                        ))
                        ->options([
                            'true' => __('filament-forms::components.select.boolean.true'),
                            'false' => __('filament-forms::components.select.boolean.false'),
                        ])
                        ->default('true')
                        ->formatStateUsing(fn (?string $state) => $state ?? 'true')
                        ->selectablePlaceholder(false)
                        ->dehydrated(fn (?string $state) => $state === 'false'),
                ];
            });
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'org.apache.kafka.connect.transforms.TimestampConverter$Value',
            'field' => $params['field'],
            'target.type' => $params['target_type'],
            ...(
                array_key_exists('format', $params)
                    ? ['format' => $params['format']]
                    : []
            ),
            ...(
                array_key_exists('unix_precision', $params)
                    ? ['unix.precision' => $params['unix_precision']]
                    : []
            ),
            ...(
                array_key_exists('replace_null_with_default', $params)
                    ? ['replace.null.with.default' => $params['replace_null_with_default']]
                    : []
            ),
            'predicate' => 'tombstone',
            'negate' => 'true',
        ];
    }
}
