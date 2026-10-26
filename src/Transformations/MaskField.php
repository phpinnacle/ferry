<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class MaskField implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'mask';
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Value;
    }

    public function accepts(JsonSchema $input): bool
    {
        return (
            $input->singleType() === 'string'
            && $input->format() !== 'date-time'
            && $input->annotation('x-ferry-reference') !== true
        );
    }

    public function output(JsonSchema $input, array $params): JsonSchema
    {
        return JsonSchema::fromArray([
            'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
        ]);
    }

    public function block(): Block
    {
        return Block::make($this->getKey())
            ->color('warning')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.mask'))
            ->icon(Heroicon::EyeSlash)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.mask_description'))
            ->schema(fn () => [
                TextInput::make('replacement')
                    ->label(__('phpinnacle-ferry::resources.transform.mask_replacement'))
                    ->default('***')
                    ->required(),
            ]);
    }

    public function connector(string $field, array $params): array
    {
        return [
            'type' => 'org.apache.kafka.connect.transforms.MaskField$Value',
            'fields' => $field,
            'replacement' => $params['replacement'],
            'predicate' => 'tombstone',
            'negate' => 'true',
        ];
    }
}
