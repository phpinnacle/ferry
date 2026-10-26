<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\Hidden;
use Illuminate\Validation\Rule;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\FieldTransforms;

final class InsertUuid implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'insert_uuid';
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Key;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $input->compatible(JsonSchema::fromArray(['type' => 'string', 'format' => 'uuid']));
    }

    public function output(JsonSchema $input, array $params): JsonSchema
    {
        return $input;
    }

    public function block(): Block
    {
        return Block::make($this->getKey())
            ->label(fn () => __('phpinnacle-ferry::resources.transform.insert_uuid'))
            ->schema(fn (FieldTransforms $component) => [
                Hidden::make('fields')
                    ->required()
                    ->rules(['string', Rule::in([implode(',', $component->getObject()->primary_key ?? [])])]),
            ]);
    }

    public function connector(string $field, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.InsertUuid$Key',
            'fields' => $params['fields'],
            'output' => $field,
        ];
    }
}
