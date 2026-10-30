<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Eidos\Forms\JsonSchemaPicker;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Forms\RecordTransformBlock;
use PHPinnacle\Jason\JsonPath;
use PHPinnacle\Jason\Schema;

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

    public function getScope(): TransformScope
    {
        return TransformScope::Record;
    }

    public function getTarget(): TransformTarget
    {
        return TransformTarget::Key;
    }

    public function accepts(JsonSchema $input): bool
    {
        return $input->singleType() === 'object';
    }

    /** @param array{fields: list<string>, output: string} $params */
    public function sourceFields(array $params): array
    {
        return array_map(fn (string $path) => (string) JsonPath::parse($path)[0], $params['fields']);
    }

    /** @param array{fields?: list<string>|null, output?: string|null} $params */
    public function output(JsonSchema $input, array $params): ?JsonSchema
    {
        if (blank($params['output'] ?? null) || blank($params['fields'] ?? null)) {
            return null;
        }

        $schema = $input->toArray();
        $schema['properties'] =
            [
                $params['output'] => [
                    'type' => 'string',
                    'title' => Str::headline($params['output']),
                    'format' => 'uuid',
                    'minLength' => 36,
                    'maxLength' => 36,
                    'x-ferry-target' => 'key',
                ],
            ]
            + ($schema['properties'] ?? []);
        $schema['required'] = [...$input->required(), $params['output']];
        $schema['x-primary-key'] = [$params['output']];

        return JsonSchema::fromArray($schema);
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label(fn (?array $state) => filled($state['output'] ?? null)
                ? $state['output']
                : __('phpinnacle-ferry::transforms.insert_uuid'))
            ->addable(
                fn (RecordTransformBlock $component) => (
                    ($component->getInputSchema()->annotation('x-primary-key') ?? []) !== []
                ),
            )
            ->parameters(function (JsonSchema $input) {
                /** @var list<string> $primaryKey */
                $primaryKey = $input->annotation('x-primary-key') ?? [];
                $properties = [];
                foreach ($primaryKey as $source) {
                    $property = $input->properties()[$source];
                    /** @var string|null $translation */
                    $translation = $property->annotation('x-ferry-trans');
                    /** @var string $label */
                    $label = $translation !== null ? __($translation) : $property->annotation('title') ?? $source;
                    $properties[$source] = Schema::fromArray([
                        ...$property->toArray(),
                        'title' => $label,
                    ]);
                }

                $paths = array_map(fn (string $source) => JsonPath::fromSegments([$source]), $primaryKey);

                return [
                    JsonSchemaPicker::make('fields')
                        ->label(__('phpinnacle-ferry::transforms.uuid_sources'))
                        ->multiple()
                        ->jsonSchema(Schema::object($properties))
                        ->default($paths)
                        ->dehydrateStateUsing(fn (?array $state) => array_values(array_filter(
                            $paths,
                            fn (string $path) => in_array($path, $state ?? [], true),
                        )))
                        ->required()
                        ->rule('size:' . count($primaryKey))
                        ->live(),
                    TextInput::make('output')
                        ->label(__('phpinnacle-ferry::transforms.uuid_field'))
                        ->default('id')
                        ->required()
                        ->maxLength(255)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->rules(['string', Rule::notIn(array_keys($input->properties()))])
                        ->validationMessages(['not_in' => __('phpinnacle-ferry::validation.uuid_field_exists')])
                        ->lazy(),
                ];
            });
    }

    /** @param array{fields: list<string>, output: string} $params
     */
    public function connector(array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.InsertUuid$Key',
            'fields' => implode(',', $this->sourceFields($params)),
            'output' => $params['output'],
        ];
    }
}
