<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class Json implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'json';
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
        return true;
    }

    public function output(Schema $input, array $params): Schema
    {
        return Schema::fromArray([
            'type' => $input->types() === [] || in_array('null', $input->types(), true)
                ? ['string', 'null']
                : 'string',
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::transforms.json'))
            ->description(fn () => __('phpinnacle-ferry::transforms.json_description'))
            ->icon(Heroicon::CodeBracket);
    }

    /** @param array{field: string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.JsonTransform$' . ucfirst($target->value),
            'fields' => $params['field'],
        ];
    }
}
