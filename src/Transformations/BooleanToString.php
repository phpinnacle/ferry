<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Jason\Schema;

final class BooleanToString implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'boolean_to_string';
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
        return $input->singleType() === 'boolean';
    }

    /** @param array<string, string> $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (!array_key_exists('true', $params) || !array_key_exists('false', $params)) {
            return null;
        }

        $nullable = in_array('null', $input->types(), true);
        /** @var list<bool|null> $values */
        $values = $input->enumValues() ?? ($nullable ? [true, false, null] : [true, false]);
        $enum = [];
        foreach ($values as $value) {
            $output = $value === null ? null : $params[$value ? 'true' : 'false'];
            if (!in_array($output, $enum, true)) {
                $enum[] = $output;
            }
        }

        $schema = [
            'type' => $nullable ? ['string', 'null'] : 'string',
            'enum' => $enum,
        ];
        $lengths = array_map(mb_strlen(...), array_filter($enum, static fn (?string $value) => $value !== null));
        if ($lengths !== []) {
            $schema['minLength'] = min($lengths);
            $schema['maxLength'] = max($lengths);
        }

        return Schema::fromArray($schema);
    }

    public function block(): InlineBlock
    {
        return InlineBlock::make($this->getKey())
            ->color('info')
            ->label(fn () => __('phpinnacle-ferry::transforms.boolean_to_string'))
            ->description(fn () => __('phpinnacle-ferry::transforms.boolean_to_string_description'))
            ->icon(Heroicon::Language)
            ->schema(fn () => array_map(
                fn (string $name) => TextInput::make($name)
                    ->label(__('phpinnacle-ferry::transforms.boolean_string_' . $name))
                    ->default($name)
                    ->trim(false)
                    ->rules(['string'])
                    // Preserve exact text: Filament treats blank scalar state as null.
                    ->mutateStateForValidationUsing(fn (Get $get) => $get->array('')[$name] ?? '')
                    ->dehydrateStateUsing(fn (Get $get) => $get->array('')[$name] ?? ''),
                ['true', 'false'],
            ));
    }

    /**
     * @param array<string, string> $params
     */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.BooleanToStringTransform$' . ucfirst($target->value),
            'fields' => $params['field'],
            'true' => $params['true'],
            'false' => $params['false'],
        ];
    }
}
