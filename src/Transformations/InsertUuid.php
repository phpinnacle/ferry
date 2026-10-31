<?php

namespace PHPinnacle\Ferry\Transformations;

use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public function accepts(Schema $input): bool
    {
        return $input->singleType() === 'object';
    }

    /** @param array{fields: list<string>} $params */
    public function sourceFields(array $params): array
    {
        return array_map(fn (string $path) => (string) JsonPath::parse($path)[0], $params['fields']);
    }

    /** @param array{fields?: list<string>|null, output?: string|null} $params */
    public function output(Schema $input, array $params): ?Schema
    {
        if (blank($params['output'] ?? null) || blank($params['fields'] ?? null)) {
            return null;
        }

        $schema = $input->keywords();
        $schema['properties'] =
            [
                $params['output'] => [
                    'type' => 'string',
                    'title' => Str::headline($params['output']),
                    'format' => 'uuid',
                    'minLength' => 36,
                    'maxLength' => 36,
                ],
            ]
            + ($schema['properties'] ?? []);
        $schema['required'] = [...$input->effectiveRequired(), $params['output']];
        $schema['x-primary-key'] = [$params['output']];

        return Schema::fromArray($schema);
    }

    /** @param array{fields?: list<string>|null}|null $state */
    private function blockLabel(?array $state): string
    {
        if (blank($state['fields'] ?? null)) {
            return __('phpinnacle-ferry::transforms.insert_uuid');
        }

        return __('phpinnacle-ferry::transforms.uuid_heading', [
            'field' => implode(', ', $this->sourceFields($state)),
        ]);
    }

    public function block(): RecordTransformBlock
    {
        return RecordTransformBlock::make($this->getKey())
            ->label($this->blockLabel(...))
            ->icon(Heroicon::FingerPrint)
            ->addable(
                fn (RecordTransformBlock $component) => (
                    ($component->getInputSchema()->annotation('x-primary-key') ?? []) !== []
                ),
            )
            ->parameters(function (Schema $input) {
                /** @var list<string> $primaryKey */
                $primaryKey = $input->annotation('x-primary-key') ?? [];
                $properties = [];
                foreach ($primaryKey as $source) {
                    $property = $input->resolveReferences()->effectiveProperties()[$source];
                    /** @var string|null $translation */
                    $translation = $property->annotation('x-ferry-trans');
                    /** @var string $label */
                    $label = $translation !== null ? __($translation) : $property->annotation('title') ?? $source;
                    $properties[$source] = Schema::fromArray([
                        ...$property->keywords(),
                        'title' => $label,
                    ]);
                }

                $paths = array_map(fn (string $source) => JsonPath::fromSegments([$source]), $primaryKey);

                return [
                    TextInput::make('output')
                        ->label(__('phpinnacle-ferry::transforms.uuid_field'))
                        ->default('id')
                        ->required()
                        ->maxLength(255)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->rules(['string', Rule::notIn(array_keys($input->resolveReferences()->effectiveProperties()))])
                        ->validationMessages(['not_in' => __('phpinnacle-ferry::validation.uuid_field_exists')])
                        ->lazy(),
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
                ];
            });
    }

    /** @param array{fields: list<string>, output: string} $params
     */
    public function connector(TransformTarget $target, array $params): array
    {
        return [
            'type' => 'org.phpinnacle.toblerone.InsertUuid$' . ucfirst($target->value),
            'fields' => implode(',', $this->sourceFields($params)),
            'output' => $params['output'],
        ];
    }
}
