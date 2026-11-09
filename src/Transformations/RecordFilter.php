<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\RecordTransformBlock;
use PHPinnacle\Jason\Schema;

final class RecordFilter implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'record_filter';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Record;
    }

    public function sourceFields(array $params): array
    {
        // JSONPath expressions do not contribute field dependencies to source capture.
        return [];
    }

    public function accepts(Schema $input): bool
    {
        return $input->singleType() === 'object';
    }

    public function output(Schema $input, array $params): Schema
    {
        return $input;
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label(__('phpinnacle-ferry::transforms.filter'))
            ->icon(Heroicon::Funnel)
            ->schema([
                Group::make([
                    Select::make('mode')
                        ->label(__('phpinnacle-ferry::transforms.filter_mode'))
                        ->options([
                            'exclude' => __('phpinnacle-ferry::transforms.filter_exclude'),
                            'include' => __('phpinnacle-ferry::transforms.filter_include'),
                        ])
                        ->default('exclude')
                        ->selectablePlaceholder(false)
                        ->required(),
                    Select::make('missing_or_null_behavior')
                        ->label(__('phpinnacle-ferry::transforms.filter_missing_or_null_behavior'))
                        ->options([
                            'include' => __('phpinnacle-ferry::transforms.filter_missing_include'),
                            'exclude' => __('phpinnacle-ferry::transforms.filter_missing_exclude'),
                            'fail' => __('phpinnacle-ferry::transforms.filter_missing_fail'),
                        ])
                        ->default('include')
                        ->selectablePlaceholder(false)
                        ->required(),
                ])->columns(2)->columnSpanFull(),
                Textarea::make('condition')
                    ->label(__('phpinnacle-ferry::transforms.record_filter_condition'))
                    ->helperText(__('phpinnacle-ferry::transforms.record_filter_condition_help'))
                    ->placeholder('$[?(@.amount >= 100 && @.active == true)]')
                    ->rows(3)
                    ->rules(['string'])
                    ->required(),
            ]);
    }

    /** @param array{condition: string, mode: string, missing_or_null_behavior: string} $params */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'io.confluent.connect.transforms.Filter$' . ucfirst($target->value),
            'filter.condition' => $params['condition'],
            'filter.type' => $params['mode'],
            'missing.or.null.behavior' => $params['missing_or_null_behavior'],
            'predicate' => 'tombstone',
            'negate' => 'true',
        ];
    }
}
