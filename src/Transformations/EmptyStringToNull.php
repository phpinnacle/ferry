<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class EmptyStringToNull implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'empty_string_to_null';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Field;
    }

    public function sourceFields(array $params): array
    {
        return [];
    }

    public function accepts(Schema $input): bool
    {
        return $input->singleType() === 'string'
        && !in_array($input->effectiveFormat(), ['date', 'time', 'date-time'], true);
    }

    public function output(Schema $input, array $params): Schema
    {
        return Schema::fromArray(['type' => ['string', 'null']]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::transforms.empty_string_to_null'))
            ->description(fn () => __('phpinnacle-ferry::transforms.empty_string_to_null_description'))
            ->icon(Heroicon::Backspace)
            ->schema([
                Toggle::make('trim')
                    ->label(__('phpinnacle-ferry::transforms.empty_string_to_null_trim'))
                    ->helperText(__('phpinnacle-ferry::transforms.empty_string_to_null_trim_help')),
            ]);
    }

    /** @param array{field: string, trim?: bool} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.EmptyStringToNullTransform$' . ucfirst($target->value),
            'fields' => $params['field'],
            'trim' => $params['trim'] ?? false ? 'true' : 'false',
        ];
    }
}
