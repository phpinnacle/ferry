<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class Trim implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'trim';
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
        return (
            $input->singleType() === 'string'
            && !in_array($input->effectiveFormat(), ['date', 'time', 'date-time'], true)
            && $input->annotation('x-ferry-reference') !== true
        );
    }

    /** @param array<string, string> $params */
    public function output(Schema $input, array $params): Schema
    {
        return Schema::fromArray([
            'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('success')
            ->label(fn () => __('phpinnacle-ferry::transforms.trim'))
            ->icon(Heroicon::ArrowsPointingIn);
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.TrimTransform$' . ucfirst($target->value),
            'fields' => $params['field'],
        ];
    }
}
