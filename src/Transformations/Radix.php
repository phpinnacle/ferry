<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class Radix implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'radix';
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
            in_array($input->singleType(), ['integer', 'string'], true)
            && !in_array($input->effectiveFormat(), ['date', 'time', 'date-time'], true)
        );
    }

    /** @param array{radix?: int|string|null} $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (($params['radix'] ?? null) === null) {
            return null;
        }

        $type = $input->singleType() === 'integer' ? 'string' : 'integer';

        return Schema::fromArray([
            'type' => in_array('null', $input->types(), true) ? [$type, 'null'] : $type,
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::transforms.radix'))
            ->description(fn () => __('phpinnacle-ferry::transforms.radix_description'))
            ->icon(Heroicon::Hashtag)
            ->schema([
                Select::make('radix')
                    ->label(__('phpinnacle-ferry::transforms.radix_base'))
                    ->helperText(__('phpinnacle-ferry::transforms.radix_base_help'))
                    ->options(array_combine(range(2, 36), array_map(strval(...), range(2, 36))))
                    ->default('16')
                    ->required(),
            ]);
    }

    /** @param array{field: string, radix: int|string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.RadixTransform$' . ucfirst($target->value),
            'fields' => $params['field'] . ':' . $params['radix'],
        ];
    }
}
