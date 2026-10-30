<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class Substring implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'substring';
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
            $input->singleType() === 'string'
            && !in_array($input->format(), ['date', 'time', 'date-time'], true)
            && $input->annotation('x-ferry-reference') !== true
        );
    }

    /** @param array<string, string> $params */
    public function output(JsonSchema $input, array $params): JsonSchema
    {
        return JsonSchema::fromArray([
            'type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string',
        ]);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.substring'))
            ->icon(Heroicon::Scissors)
            ->schema(fn () => [
                Group::make()
                    ->schema([
                        TextInput::make('start')
                            ->label(__('phpinnacle-ferry::resources.transform.substring_start'))
                            ->helperText(__('phpinnacle-ferry::resources.transform.substring_start_help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2_147_483_647)
                            ->default('0')
                            ->required()
                            ->dehydrateStateUsing(fn (int|string $state) => (string) $state),
                        TextInput::make('end')
                            ->label(__('phpinnacle-ferry::resources.transform.substring_end'))
                            ->helperText(__('phpinnacle-ferry::resources.transform.substring_end_help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2_147_483_647)
                            ->gte('start')
                            ->dehydrated(fn (int|string|null $state) => filled($state))
                            ->dehydrateStateUsing(fn (int|string $state) => (string) $state),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.SubstringTransform$Value',
            'fields' =>
                $params['field']
                    . ':'
                    . $params['start']
                    . (array_key_exists('end', $params) ? ':' . $params['end'] : ''),
        ];
    }
}
