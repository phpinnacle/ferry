<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Concerns\CanBeCompact;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Forms\Concerns\HasColumnSpans;
use PHPinnacle\Ferry\Forms\Concerns\HasSourceSettings;
use PHPinnacle\Ferry\Forms\Concerns\Searchable;

/**
 * @phpstan-import-type MappingItem from JsonSchema
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-type SourceState array{kind: 'source', source: string, target: mixed}&array<string, mixed>
 * @phpstan-type StaticState array{kind: 'static', target: string, static: mixed}&array<string, mixed>
 * @phpstan-type State list<SourceState|StaticState>
 */
class FieldBinding extends Field
{
    use CanBeCompact;
    use CanBeContained;
    use HasColumnSpans;
    use HasSourceSettings;
    use Searchable;

    private const string CONSTANT_PREFIX = 'constant_';

    protected string $view = 'phpinnacle-ferry::forms.field-binding';

    /** @var Definition|Closure(): Definition */
    protected array|Closure $jsonSchema = [];

    /** @var string|Closure(): string */
    protected string|Closure $sourceLabel = 'Source';

    /** @var string|Closure(): string */
    protected string|Closure $targetLabel = 'Binding';

    /** @var Field|Closure(): Field|null */
    protected Field|Closure|null $simpleField = null;

    protected bool $hasStaticValues = false;

    /** @var list<string>|null */
    protected ?array $cachedBoundKeys = null;

    /** @var array<string, string>|Closure(): array<string, string> */
    protected array|Closure $warnings = [];

    /** @var Definition|null */
    private ?array $cachedSourceDefinition = null;

    /** @var list<MappingItem> */
    private array $cachedSources = [];

    /** @param array<string, string>|Closure(): array<string, string> $warnings */
    public function warnings(array|Closure $warnings): static
    {
        $this->warnings = $warnings;

        return $this;
    }

    /** @return array<string, string> */
    public function getWarnings(): array
    {
        return $this->evaluate($this->warnings);
    }

    /** @param Definition|Closure $schema */
    public function jsonSchema(array|Closure $schema): static
    {
        $this->jsonSchema = $schema;

        return $this;
    }

    /**
     * @param string|Closure(): string $source
     * @param string|Closure(): string $dest
     */
    public function labels(string|Closure $source = 'Source', string|Closure $dest = 'Binding'): static
    {
        $this->sourceLabel = $source;
        $this->targetLabel = $dest;

        return $this;
    }

    public function getSourceLabel(): string
    {
        return $this->evaluate($this->sourceLabel);
    }

    public function getTargetLabel(): string
    {
        return $this->evaluate($this->targetLabel);
    }

    /** @return list<MappingItem> */
    public function getSources(): array
    {
        $definition = $this->evaluate($this->jsonSchema);
        if ($this->cachedSourceDefinition !== $definition) {
            $this->cachedSources = JsonSchema::fromArray($definition)->toMappingItems();
            $this->cachedSourceDefinition = $definition;
        }

        return $this->cachedSources;
    }

    /** @param Field|Closure(): Field|null $field */
    public function simple(Field|Closure|null $field): static
    {
        $this->simpleField = $field;
        $this->schema(fn (self $component) => [$component->getSimpleField()]);

        return $this;
    }

    public function getSimpleField(): ?Field
    {
        return $this->simpleField !== null ? $this->evaluate($this->simpleField) : null;
    }

    public function staticValues(bool $condition = true): static
    {
        $this->hasStaticValues = $condition;

        return $this;
    }

    public function hasStaticValues(): bool
    {
        return $this->hasStaticValues;
    }

    public function getBindingKey(string $source): string
    {
        return 'source_' . bin2hex($source);
    }

    /** @return array<Schema> */
    public function getBindings(): array
    {
        return array_filter(
            $this->getCachedDefaultChildSchemas(),
            static fn (string $key) => !str_starts_with($key, 'settings_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function getBindingSchema(string $source): Schema
    {
        $key = $this->getBindingKey($source);
        $bindings = $this->getBindings();
        if (array_key_exists($key, $bindings)) {
            return $bindings[$key];
        }

        $schema = $this->makeBindingSchema($source, 'bindings.' . $key);

        /** @var array{bound: array<string, bool>, bindings: array<string, array<string, mixed>>, previews: array<string, array<string, mixed>>} $state */
        $state = $this->getRawState();
        if (!($state['bound'][$key] ?? false) && !array_key_exists($key, $state['bindings'])) {
            $schema->fill();
            /** @var array{bindings: array<string, array<string, mixed>>, previews: array<string, array<string, mixed>>} $state */
            $state = $this->getRawState();
            $state['previews'][$key] = $state['bindings'][$key];
            $this->rawState($state);
        }

        return $schema;
    }

    /** @return list<array<string, mixed>> */
    public function getBindingValues(): array
    {
        /** @var array{sources?: array<string, array<string, mixed>>, bindings?: array<string, mixed>, bound?: array<string, bool>, previews?: array<string, mixed>}|null $state */
        $state = $this->getRawState();

        return $this->dehydrateBindings($state);
    }

    /** @return array<Schema> */
    public function getConstants(): array
    {
        return array_filter($this->getBindings(), self::isConstantKey(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed>|null $hydratedDefaultState
     * @param array<string, true> $appliedStateCastPaths
     */
    public function hydrateState(
        ?array &$hydratedDefaultState,
        bool $shouldCallHydrationHooks = true,
        bool $shouldApplyStateCasts = true,
        array &$appliedStateCastPaths = [],
    ): void {
        $this->hydrateDefaultState($hydratedDefaultState);

        $bindings = $this->getHydratedBindings();
        /** @var State $state */
        $state = $this->getRawState() ?? [];
        $sources = [];
        foreach ($state as $entry) {
            if ($entry['kind'] === 'source') {
                /** @var array<string, mixed> $settings */
                $settings = Arr::except($entry, ['kind', 'source', 'target']);
                if ($settings !== []) {
                    $sources[$entry['source']] = $settings;
                }
            }
        }
        $sources = $this->encodeSourceSettings($sources);
        $bound = array_fill_keys(
            array_filter(array_keys($bindings), fn (string $key) => !self::isConstantKey($key)),
            true,
        );
        $this->rawState(['sources' => $sources, 'bindings' => $bindings, 'bound' => $bound, 'previews' => []]);
        $this->clearCachedChildSchemas();
        $this->fillSourceSettingsDefaults($sources);

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);
            if (array_key_exists($key, $bound)) {
                $this->makeBindingSchema($source['id'], 'previews.' . $key)->fill();
            } else {
                $this->getBindingSchema($source['id']);
            }
        }

        if ($hydratedDefaultState !== null) {
            $defaults = $hydratedDefaultState;
            Arr::set($defaults, $this->getStatePath(), $this->getRawState());
            /** @var array<string, mixed> $defaults */
            $hydratedDefaultState = $defaults;
        }

        parent::hydrateState(
            $hydratedDefaultState,
            $shouldCallHydrationHooks,
            $shouldApplyStateCasts,
            $appliedStateCastPaths,
        );
    }

    /** @return array<Schema> */
    public function getDefaultChildSchemas(): array
    {
        $this->cachedBoundKeys = $this->getBoundKeys();
        $bindings = $this->makeSourceSettingsSchemas();
        $sources = array_column($this->getSources(), 'id');
        $sourcesByKey = array_combine(array_map($this->getBindingKey(...), $sources), $sources);

        foreach ($this->cachedBoundKeys as $key) {
            if (!self::isConstantKey($key)) {
                $bindings[$key] = $this->makeBindingSchema($sourcesByKey[$key], 'bindings.' . $key);

                continue;
            }
            $bindings[$key] = $this
                ->getConstantSchema($key)
                ->statePath('bindings.' . $key)
                ->inlineLabel(false)
                ->getClone();
        }

        return $bindings;
    }

    private function makeBindingSchema(string $source, string $statePath): Schema
    {
        /** @var Schema $schema The default child schema always exists. */
        $schema = $this->getChildSchema();
        $schema = $schema->getClone()->statePath($statePath)->inlineLabel(false);

        if ($this->simpleField !== null) {
            /** @var Field $field */
            $field = $schema->getComponents()[0];
            if (!$field->hasDefaultState()) {
                $field->default($source);
            }
        }

        return $schema;
    }

    protected function areCachedDefaultChildSchemasFresh(): bool
    {
        return $this->cachedBoundKeys === $this->getBoundKeys() && $this->areSourceSettingsSchemasFresh();
    }

    private function getConstantSchema(string $key, ?string $defaultTarget = null): Schema
    {
        /** @var array<Component> $fields */
        $fields = $this->evaluate($this->sourceSettingsSchema, [
            'source' => $key,
            'inputSchema' => fn () => fn () => $this->getConstantInputSchema($key),
        ]);
        /** @var Field $target */
        $target = $this->getSimpleField();
        $target = clone $target;
        if ($defaultTarget !== null) {
            $target->default($defaultTarget);
        }

        return $this->makeChildSchema('constants')->components([
            Group::make([
                TextInput::make('static')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->hiddenLabel()
                    ->inlineLabel(false)
                    ->placeholder(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->default('')
                    ->required()
                    ->rule(fn () => $this->validateStaticValue(...)),
                ...$fields,
            ])
                ->key('input')
                ->statePath('input')
                ->dense(),
            Group::make([$target])->key('output')->statePath('output'),
        ]);
    }

    public function getConstantInputSchema(string $constant): JsonSchema
    {
        $value = data_get($this->getBindingState(), $constant . '.input.static');

        return JsonSchema::fromArray(['type' => match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_array($value) => 'array',
            is_object($value) => 'object',
            default => 'string',
        }]);
    }

    public function getConstantTarget(string $constant): mixed
    {
        /** @var Field $simple */
        $simple = $this->getSimpleField();

        return data_get($this->getBindingState(), $constant . '.output.' . $simple->getName());
    }

    public function getBindAction(): Action
    {
        return Action::make('bind')
            ->label(__('phpinnacle-ferry::resources.binding.bind'))
            ->icon('heroicon-m-link')
            ->iconButton()
            ->size('lg')
            ->tooltip(__('phpinnacle-ferry::resources.binding.bind'))
            ->alpineClickHandler($this->bindClickHandler(...))
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->bindSource($arguments));
    }

    public function getUnbindAction(): Action
    {
        return Action::make('unbind')
            ->label(__('phpinnacle-ferry::resources.binding.unbind'))
            ->alpineClickHandler($this->unbindClickHandler(...))
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->unbindSource($arguments));
    }

    /** @param array{source: string} $arguments */
    private function bindClickHandler(array $arguments): string
    {
        return 'bind(' . Js::from($this->getBindingKey($arguments['source']))->toHtml() . ')';
    }

    /** @param array{source: string} $arguments */
    private function unbindClickHandler(array $arguments): string
    {
        return 'unbind(' . Js::from($this->getBindingKey($arguments['source']))->toHtml() . ')';
    }

    public function getAddConstantAction(): Action
    {
        return Action::make('addConstant')
            ->label(__('phpinnacle-ferry::resources.binding.add_constant'))
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->button()
            ->disabled(fn (self $component) => $component->isDisabled() || !$component->hasStaticValues())
            ->action(fn (self $component) => $component->addConstant())
            ->after(fn (self $component) => $component->renderStaticValues());
    }

    public function getRemoveConstantAction(): Action
    {
        return Action::make('removeConstant')
            ->label(__('phpinnacle-ferry::resources.binding.remove_constant'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->iconButton()
            ->disabled(fn (self $component) => $component->isDisabled() || !$component->hasStaticValues())
            ->action(fn (array $arguments, self $component) => $component->removeConstant($arguments))
            ->after(fn (self $component) => $component->renderStaticValues());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([]);
        $this->childComponents([
            View::make('phpinnacle-ferry::forms.field-binding-static-values')->key('staticValues'),
        ], 'staticValues');
        $this->default([]);
        $this->rules(fn (self $component) => ['bail', 'array', $component->validateBindings(...)]);
        $this->registerActions([
            fn (self $component) => $component->getBindAction(),
            fn (self $component) => $component->getUnbindAction(),
            fn (self $component) => $component->getAddConstantAction(),
            fn (self $component) => $component->getRemoveConstantAction(),
        ]);
        $this->mutateDehydratedStateUsing($this->dehydrateBindings(...));
    }

    private function renderStaticValues(): void
    {
        /** @var Component $component */
        $component = $this->getChildSchemas()['staticValues']->getComponent('staticValues');
        $component->partiallyRender();
    }

    /**
     * @param array{sources?: array<string, array<string, mixed>>, bindings?: array<string, mixed>, bound?: array<string, bool>, previews?: array<string, mixed>}|null $state
     * @return list<array<string, mixed>>
     */
    private function dehydrateBindings(?array $state): array
    {
        $sources = $this->decodeSourceSettings($state['sources'] ?? []);
        $bindings = $state['bindings'] ?? [];
        $simple = $this->getSimpleField();
        $boundKeys = $this->getBoundKeys();
        $rows = [];
        foreach ($this->getSources() as $source) {
            $id = $source['id'];
            $key = $this->getBindingKey($id);
            $target = null;
            if (in_array($key, $boundKeys, true)) {
                $target = $simple !== null ? data_get($bindings[$key], $simple->getName()) : $bindings[$key];
            }
            $rows[] = ['kind' => 'source', 'source' => $id, 'target' => $target] + ($sources[$id] ?? []);
        }
        foreach ($state['bindings'] ?? [] as $key => $data) {
            if (self::isConstantKey((string) $key)) {
                /** @var array{input: array<string, mixed>, output: array<string, mixed>} $data */
                /** @var Field $simple */
                $simple = $this->getSimpleField();
                $rows[] =
                    ['kind' => 'static', 'target' => data_get($data['output'], $simple->getName())] + $data['input'];
            }
        }

        return $rows;
    }

    /** @param array<array-key, mixed> $arguments */
    private function bindSource(array $arguments): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $key = $this->getBindingKey($this->validateSource($arguments));
        $bindings = $this->getBindingState();

        if (in_array($key, $this->getBoundKeys(), true)) {
            return;
        }

        $bindings[$key] = [];
        $this->writeBindingState($bindings);
        /** @var array{bound: array<string, bool>} $state */
        $state = $this->getRawState();
        $state['bound'][$key] = true;
        $this->rawState($state);
        $this->getBindings()[$key]->fill();
        $this->callAfterStateUpdated();
    }

    /** @param array<array-key, mixed> $arguments */
    private function unbindSource(array $arguments): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $key = $this->getBindingKey($this->validateSource($arguments));
        /** @var array{bound: array<string, bool>, bindings: array<string, mixed>, previews: array<string, mixed>} $state */
        $state = $this->getRawState();
        unset($state['bound'][$key]);
        $state['bindings'][$key] = $state['previews'][$key];
        $this->rawState($state);
        $this->callAfterStateUpdated();
    }

    private function addConstant(): void
    {
        if ($this->isDisabled() || !$this->hasStaticValues()) {
            return;
        }

        $targets = array_column($this->getBindingValues(), 'target');
        $number = 1;
        while (in_array('static_' . $number, $targets, true)) {
            $number++;
        }
        $key = self::CONSTANT_PREFIX . bin2hex(random_bytes(6));
        $bindings = $this->getBindingState();
        $bindings[$key] = [];
        $this->writeBindingState($bindings);
        $this
            ->getConstantSchema($key, 'static_' . $number)
            ->statePath('bindings.' . $key)
            ->getClone()
            ->fill();
        $this->callAfterStateUpdated();
    }

    /** @param array<array-key, mixed> $arguments */
    private function removeConstant(array $arguments): void
    {
        if ($this->isDisabled() || !$this->hasStaticValues()) {
            return;
        }

        /** @var array{constant: string} $validated */
        $validated = Validator::make($arguments, [
            'constant' => ['required', 'string', 'starts_with:' . self::CONSTANT_PREFIX],
        ])->validate();
        $bindings = $this->getBindingState();
        unset($bindings[$validated['constant']]);
        $this->writeBindingState($bindings);
        $this->callAfterStateUpdated();
    }

    /** @return array<string, mixed> */
    private function getBindingState(): array
    {
        /** @var array{bindings?: array<string, mixed>} $state */
        $state = $this->getRawState() ?? [];

        return $state['bindings'] ?? [];
    }

    /** @param array<string, mixed> $bindings */
    private function writeBindingState(array $bindings): void
    {
        /** @var array<string, mixed> $state */
        $state = $this->getRawState() ?? [];
        $this->rawState([...$state, 'bindings' => $bindings]);
    }

    /** @return array<string, mixed> */
    private function getHydratedBindings(): array
    {
        $bindings = [];
        $simple = $this->getSimpleField();

        /** @var State $state */
        $state = $this->getRawState() ?? [];

        foreach ($state as $entry) {
            $data = [];
            if ($entry['kind'] === 'static') {
                /** @var Field $simple */
                data_set($data, $simple->getName(), $entry['target']);
                $bindings[self::CONSTANT_PREFIX . bin2hex($entry['target'])] = [
                    'input' => Arr::except($entry, ['kind', 'target']),
                    'output' => $data,
                ];

                continue;
            }

            if ($entry['target'] === null) {
                continue;
            }
            $source = $entry['source'];
            $binding = $entry['target'];
            if ($simple !== null) {
                data_set($data, $simple->getName(), $binding);
            } else {
                $data = $binding;
            }

            $bindings[$this->getBindingKey((string) $source)] = $data;
        }

        return $bindings;
    }

    public function isSourceUsed(string $source): bool
    {
        return in_array($this->getBindingKey($source), $this->getBoundKeys(), true);
    }

    /** @return list<string> */
    private function getBoundKeys(): array
    {
        $state = $this->getBindingState();
        /** @var array{bound?: array<string, bool>}|null $rawState */
        $rawState = $this->getRawState();
        $bound = $rawState['bound'] ?? [];

        $keys = [];

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);

            if (($bound[$key] ?? false) === true && is_array($state[$key] ?? null)) {
                $keys[] = $key;
            }
        }

        if ($this->hasStaticValues()) {
            foreach ($state as $key => $data) {
                if (self::isConstantKey((string) $key) && is_array($data)) {
                    $keys[] = (string) $key;
                }
            }
        }

        return $keys;
    }

    private static function isConstantKey(string $key): bool
    {
        return str_starts_with($key, self::CONSTANT_PREFIX);
    }

    /** @param array<array-key, mixed> $arguments */
    private function validateSource(array $arguments): string
    {
        /** @var array{source: string} $validated */
        $validated = Validator::make($arguments, [
            'source' => ['required', 'string', Rule::in(array_column($this->getSources(), 'id'))],
        ])->validate();

        return $validated['source'];
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateStaticValue(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_scalar($value)) {
            $fail('The :attribute field must be a scalar value.')->translate();
        }
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateBindings(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            return;
        }

        $validator = Validator::make(['state' => $value], [
            'state' => ['array:sources,bindings,bound,previews'],
            'state.sources' => ['array', Rule::array(array_values($this->getSourceStateKeys()))],
            'state.sources.*' => ['array'],
            'state.bindings' => ['array'],
            'state.previews' => ['array'],
            'state.bound' => ['present', 'array', Rule::array(array_values($this->getSourceStateKeys()))],
            'state.bound.*' => ['boolean:strict'],
        ]);
        if ($validator->fails()) {
            $fail($validator->errors()->first())->translate();

            return;
        }
        /** @var array{state: array{bindings?: array<string, mixed>, bound?: array<string, bool>}} $validated */
        $validated = $validator->validated();
        $value = $validated['state']['bindings'] ?? [];
        $bound = $validated['state']['bound'] ?? [];

        foreach ($bound as $key => $isBound) {
            if ($isBound && !array_key_exists($key, $value)) {
                $fail('The :attribute field contains an invalid binding value.')->translate();

                return;
            }
        }
        $value = array_filter(
            $value,
            fn (string $key) => self::isConstantKey($key) || ($bound[$key] ?? false),
            ARRAY_FILTER_USE_KEY,
        );

        $sourceKeys = $this->getSourceStateKeys();
        $keys = array_values($sourceKeys);
        $outputKeys = [];
        $simple = $this->getSimpleField();

        foreach ($value as $key => $binding) {
            $isConstant = $this->hasStaticValues() && self::isConstantKey((string) $key);

            if (!$isConstant && !in_array($key, $keys, true)) {
                $fail('The :attribute field contains an unknown source.')->translate();

                return;
            }

            if (!is_array($binding)) {
                $fail('The :attribute field contains an invalid binding value.')->translate();

                return;
            }

            if ($isConstant) {
                $validator = Validator::make([
                    'input' => $binding['input'] ?? null,
                    'output' => $binding['output'] ?? null,
                    'target' => data_get($binding, 'output.' . $simple?->getName()),
                ], [
                    'input' => ['required', 'array'],
                    'output' => ['required', 'array'],
                    'target' => ['required', 'string', Rule::notIn($outputKeys)],
                ]);
                if ($validator->fails()) {
                    $fail($validator->errors()->first())->translate();

                    return;
                }
                /** @var array{target: string, static: mixed} $constant */
                $constant = $validator->validated();
                $outputKeys[] = $constant['target'];
            }

            if (!$isConstant && ($bound[$key] ?? false) && $simple !== null) {
                $destination = data_get($binding, $simple->getName());

                if (!is_string($destination) || $destination === '') {
                    $fail('The :attribute field must bind each source to a non-empty string.')->translate();

                    return;
                }
            }
        }
    }
}
