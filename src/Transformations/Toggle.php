<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Support\Icons\Heroicon;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformTarget;

final class Toggle implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'toggle';
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Value;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $input->singleType() === 'boolean';
    }

    public function output(JsonSchema $input, array $params): JsonSchema
    {
        $schema = ['type' => in_array('null', $input->types(), true) ? ['boolean', 'null'] : 'boolean'];
        /** @var list<bool|null>|null $values */
        $values = $input->enumValues();
        if ($values !== null) {
            $schema['enum'] = array_map(static fn (?bool $value) => $value === null ? null : !$value, $values);
        }

        return JsonSchema::fromArray($schema);
    }

    public function block(): Block
    {
        return Block::make($this->getKey())
            ->color('success')
            ->label(fn () => __('phpinnacle-ferry::resources.transform.toggle'))
            ->description(fn () => __('phpinnacle-ferry::resources.transform.toggle_description'))
            ->icon(Heroicon::ArrowsRightLeft);
    }

    public function connector(string $field, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.ToggleTransform$Value',
            'fields' => $field,
        ];
    }
}
