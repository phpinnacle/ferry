<?php

namespace PHPinnacle\Ferry\Transformations;

use Closure;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class StringMap implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'string_map';
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

    /** @param array{mapping?: array<array-key, string>} $params */
    public function output(JsonSchema $input, array $params): ?JsonSchema
    {
        if (($params['mapping'] ?? []) === []) {
            return null;
        }

        $schema = ['type' => in_array('null', $input->types(), true) ? ['string', 'null'] : 'string'];
        /** @var list<string|null>|null $values */
        $values = $input->enumValues();
        if ($values !== null) {
            $schema['enum'] = [];
            foreach ($values as $value) {
                $output = $value === null ? null : $params['mapping'][$value] ?? $value;
                if (!in_array($output, $schema['enum'], true)) {
                    $schema['enum'][] = $output;
                }
            }

            $lengths = array_map(
                mb_strlen(...),
                array_filter($schema['enum'], static fn (?string $value) => $value !== null),
            );
            if ($lengths !== []) {
                $schema['minLength'] = min($lengths);
                $schema['maxLength'] = max($lengths);
            }
        } elseif ($input->maxLength() !== null) {
            $schema['maxLength'] = max($input->maxLength(), max(array_map(mb_strlen(...), $params['mapping'])));
        }

        return JsonSchema::fromArray($schema);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::transforms.string_map'))
            ->description(fn () => __('phpinnacle-ferry::transforms.string_map_description'))
            ->icon(Heroicon::ArrowsRightLeft)
            ->schema(fn () => [
                KeyValue::make('mapping')
                    ->label(__('phpinnacle-ferry::transforms.string_map_mapping'))
                    ->keyLabel(__('phpinnacle-ferry::transforms.string_map_source'))
                    ->valueLabel(__('phpinnacle-ferry::transforms.string_map_replacement'))
                    ->helperText(__('phpinnacle-ferry::transforms.string_map_help'))
                    ->required()
                    ->rules(fn (Get $get) => [
                        'array',
                        function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            /** @var Closure(string): PotentiallyTranslatedString $fail */
                            if (
                                !is_array($value)
                                || array_any(
                                    $get->array('mapping'),
                                    static fn (mixed $replacement) => !is_string($replacement),
                                )
                            ) {
                                $fail('phpinnacle-ferry::validation.string_map_values')->translate();
                            }
                        },
                    ]),
            ]);
    }

    /**
     * @param array{field: string, mapping: array<array-key, string>} $params
     */
    public function connector(array $params): array
    {
        $config = [
            'type' => 'org.phpinnacle.toblerone.StringMapTransform$Value',
            'field' => $params['field'],
        ];
        foreach ($params['mapping'] as $source => $replacement) {
            $config['mapping.' . $source] = $replacement;
        }

        return $config;
    }
}
