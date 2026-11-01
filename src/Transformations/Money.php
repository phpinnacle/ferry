<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class Money implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'money';
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
        return in_array($input->singleType(), ['number', 'integer'], true);
    }

    /** @param array<string, string> $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (($params['currency'] ?? null) === null) {
            return null;
        }

        return Schema::fromArray([
            'type' => in_array('null', $input->types(), true) ? ['integer', 'null'] : 'integer',
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('success')
            ->label(fn () => __('phpinnacle-ferry::transforms.money'))
            ->description(fn () => __('phpinnacle-ferry::transforms.money_description'))
            ->icon(Heroicon::Banknotes)
            ->schema([
                TextInput::make('currency')
                    ->label(__('phpinnacle-ferry::transforms.money_currency'))
                    ->helperText(__('phpinnacle-ferry::transforms.money_currency_help'))
                    ->placeholder('RUB')
                    ->rules(['string', 'regex:/^[A-Z]{3}$/D'])
                    ->required(),
            ]);
    }

    /** @param array{field: string, currency: string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.MoneyTransform$' . ucfirst($target->value),
            'fields' => $params['field'],
            'currency' => $params['currency'],
            'mode' => 'minor',
        ];
    }
}
