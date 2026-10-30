<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\SchemaCompatibility;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\KafkaType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class Cast implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'cast';
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

    public function accepts(Schema $input): bool
    {
        return SchemaCompatibility::make()->accepts($input, Schema::fromArray(['type' => [
            'string',
            'integer',
            'number',
            'boolean',
            'null',
        ]]));
    }

    /** @param array<string, string> $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (($params['type'] ?? null) === null) {
            return null;
        }

        $schema = ['type' => KafkaType::from($params['type'])->jsonSchemaType()];

        if ($input->types() === [] || in_array('null', $input->types(), true)) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return Schema::fromArray($schema);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->label(fn () => __('phpinnacle-ferry::transforms.cast'))
            ->description(fn () => __('phpinnacle-ferry::transforms.cast_description'))
            ->icon(Heroicon::ArrowPath)
            ->schema(fn (?Schema $input) => [
                Select::make('type')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.constant_type'))
                    ->options(KafkaType::class)
                    ->dehydrated(fn (?KafkaType $state) => $state !== null)
                    ->dehydrateStateUsing(fn (KafkaType $state) => $state->value)
                    ->disableOptionWhen(
                        fn (string $value) => (
                            $input?->singleType() === $value
                            && $input->effectiveFormat() === null
                            && $input->annotation('x-ferry-reference') !== true
                        ),
                    )
                    ->required(),
                Select::make('replace_null_with_default')
                    ->label(__('phpinnacle-ferry::transforms.cast_replace_null_with_default'))
                    ->helperText(__('phpinnacle-ferry::transforms.cast_replace_null_with_default_help'))
                    ->options([
                        'true' => __('filament-forms::components.select.boolean.true'),
                        'false' => __('filament-forms::components.select.boolean.false'),
                    ])
                    ->default('true')
                    ->formatStateUsing(fn (?string $state) => $state ?? 'true')
                    ->selectablePlaceholder(false)
                    // Keep the default implicit in stored transform settings.
                    ->dehydrated(fn (?string $state) => $state === 'false'),
            ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'org.apache.kafka.connect.transforms.Cast$Value',
            'spec' => sprintf('%s:%s', $params['field'], $params['type']),
            ...(
                array_key_exists('replace_null_with_default', $params)
                    ? ['replace.null.with.default' => $params['replace_null_with_default']]
                    : []
            ),
        ];
    }
}
