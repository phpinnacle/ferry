<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class Filter implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'filter';
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
        return true;
    }

    /** @param array<string, string> $params */
    public function output(JsonSchema $input, array $params): JsonSchema
    {
        return $input;
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->label(fn () => __('phpinnacle-ferry::resources.transform.filter'))
            ->icon(Heroicon::Funnel)
            ->description(fn () => __('phpinnacle-ferry::resources.transform.filter_description'))
            ->schema(function (?JsonSchema $input) {
                $example = match ($input?->singleType()) {
                    'string' => "@ == 'value'",
                    'integer' => '@ >= 100',
                    'number' => '@ >= 100.5',
                    'boolean' => '@ == true',
                    'array' => '@ empty false',
                    'object' => '@.price > 100',
                    'null' => '@ == null',
                    default => '@ != null',
                };

                return [
                    Group::make([
                        Select::make('mode')
                            ->label(__('phpinnacle-ferry::resources.transform.filter_mode'))
                            ->options([
                                'exclude' => __('phpinnacle-ferry::resources.transform.filter_exclude'),
                                'include' => __('phpinnacle-ferry::resources.transform.filter_include'),
                            ])
                            ->default('exclude')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('missing_or_null_behavior')
                            ->label(__('phpinnacle-ferry::resources.transform.filter_missing_or_null_behavior'))
                            ->options([
                                'include' => __('phpinnacle-ferry::resources.transform.filter_missing_include'),
                                'exclude' => __('phpinnacle-ferry::resources.transform.filter_missing_exclude'),
                                'fail' => __('phpinnacle-ferry::resources.transform.filter_missing_fail'),
                            ])
                            ->default('include')
                            ->selectablePlaceholder(false)
                            ->required(),
                    ])->columns(2)->columnSpanFull(),
                    Textarea::make('condition')
                        ->label(__('phpinnacle-ferry::resources.transform.filter_condition'))
                        ->helperText(__('phpinnacle-ferry::resources.transform.filter_condition_help', [
                            'example' => $example,
                        ]))
                        ->placeholder($example)
                        ->rows(3)
                        ->rules(['string'])
                        ->required(),
                ];
            });
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'io.confluent.connect.transforms.Filter$Value',
            'filter.condition' => $this->condition($params['field'], $params['condition']),
            'filter.type' => $params['mode'],
            'missing.or.null.behavior' => $params['missing_or_null_behavior'],
            'predicate' => 'tombstone',
            'negate' => 'true',
        ];
    }

    private function condition(string $field, string $predicate): string
    {
        $path = "['" . addcslashes($field, "\\'") . "']";
        // Bind references without rewriting quoted values, regexes, or nested filter item references.
        preg_match_all(<<<'REGEX'
            ~'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|/(?:\\.|[^/\\])*/[a-z]*|\[\s*\?\s*\(|\[|\]|(?<reference>(?<![\pL\pN_.-])[@$])|.~us
            REGEX, $predicate, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $brackets = [];
        $filters = 0;
        $condition = '';
        foreach ($matches as $match) {
            $fragment = $match[0];
            if ($fragment === '[') {
                $brackets[] = false;
            } elseif (str_starts_with($fragment, '[')) {
                $brackets[] = true;
                $filters++;
            } elseif ($fragment === ']') {
                if (array_pop($brackets)) {
                    $filters--;
                }
            } elseif ($match['reference'] !== null && ($fragment === '$' || $filters === 0)) {
                $fragment .= $path;
            }
            $condition .= $fragment;
        }

        return '$[?(' . $condition . ')]';
    }
}
