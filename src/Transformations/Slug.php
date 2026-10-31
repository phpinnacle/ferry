<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use PHPinnacle\Eidos\Forms\JsonSchemaPicker;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\RecordTransformBlock;
use PHPinnacle\Jason\JsonPath;
use PHPinnacle\Jason\Schema;

final class Slug implements Transform
{
    public static function make(): self
    {
        return new self;
    }

    public function getKey(): string
    {
        return 'slug';
    }

    public function getScope(): TransformScope
    {
        return TransformScope::Record;
    }

    public function accepts(Schema $input): bool
    {
        return $input->singleType() === 'object';
    }

    /** @param array{input: string} $params */
    public function sourceFields(array $params): array
    {
        /** @var string $source */
        $source = JsonPath::parse($params['input'])[0];

        return [$source];
    }

    /** @param array{input?: string|null, output?: string|null, separators?: string|null} $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (blank($params['input'] ?? null) || blank($params['output'] ?? null)) {
            return null;
        }

        try {
            $segments = JsonPath::parse($params['input']);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (count($segments) !== 1 || !is_string($segments[0])) {
            return null;
        }

        $source = $input->resolveReferences()->effectiveProperties()[$segments[0]] ?? null;
        if ($source?->singleType() !== 'string') {
            return null;
        }

        /** @var array{properties: array<string, array<string, mixed>>, ...} $schema */
        $schema = $input->keywords();
        $properties = $schema['properties'];
        $sourceOffset = array_search($segments[0], array_keys($properties), strict: true) + 1;
        $schema['properties'] =
            array_slice($properties, 0, $sourceOffset, preserve_keys: true)
            + [
                $params['output'] => [
                    'type' => in_array('null', $source->types(), true) ? ['string', 'null'] : 'string',
                    'title' => Str::headline($params['output']),
                ],
            ]
            + array_slice($properties, $sourceOffset, preserve_keys: true);
        if (in_array($segments[0], $input->effectiveRequired(), true)) {
            $schema['required'] = [...$input->effectiveRequired(), $params['output']];
        }

        return Schema::fromArray($schema);
    }

    /** @param array{input?: string|null}|null $state */
    private function blockLabel(?array $state): string
    {
        if (blank($state['input'] ?? null)) {
            return __('phpinnacle-ferry::transforms.slug');
        }

        return __('phpinnacle-ferry::transforms.slug_heading', [
            'field' => implode(', ', $this->sourceFields($state)),
        ]);
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label($this->blockLabel(...))
            ->icon(Heroicon::Link)
            ->parameters(function (Schema $input) {
                $properties = [];
                foreach ($input->resolveReferences()->effectiveProperties() as $name => $property) {
                    if ($property->singleType() === 'string') {
                        /** @var string|null $translation */
                        $translation = $property->annotation('x-ferry-trans');
                        /** @var string $label */
                        $label = $translation !== null ? __($translation) : $property->annotation('title') ?? $name;
                        $properties[$name] = Schema::fromArray([...$property->keywords(), 'title' => $label]);
                    }
                }

                return [
                    TextInput::make('output')
                        ->label(__('phpinnacle-ferry::transforms.uuid_field'))
                        ->default('slug')
                        ->required()
                        ->maxLength(255)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->rules(['string', Rule::notIn(array_keys($input->resolveReferences()->effectiveProperties()))])
                        ->validationMessages(['not_in' => __('phpinnacle-ferry::validation.uuid_field_exists')])
                        ->lazy(),
                    JsonSchemaPicker::make('input')
                        ->label(__('phpinnacle-ferry::transforms.slug_input'))
                        ->jsonSchema(Schema::object($properties))
                        ->live()
                        ->required(),
                    TextInput::make('separators')
                        ->label(__('phpinnacle-ferry::transforms.slug_separators'))
                        ->rules(['nullable', 'string']),
                ];
            });
    }

    /** @param array{input: string, output: string, separators?: string|null} $params
     */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.SlugTransform$' . ucfirst($target->value),
            'input' => $this->sourceFields($params)[0],
            'output' => $params['output'],
            'separators' => $params['separators'] ?? '',
        ];
    }
}
