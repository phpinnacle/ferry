<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Concerns\CanBeCompact;
use Filament\Schemas\Schema;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Forms\Concerns\HasSourceSettings;
use PHPinnacle\Ferry\Forms\Concerns\Searchable;

/**
 * @phpstan-import-type MappingItem from JsonSchema
 * @phpstan-import-type Definition from JsonSchema
 */
class FieldBinding extends Field
{
    use CanBeCompact;
    use CanBeContained;
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

    /** @var array<Component>|Closure(): array<Component>|null */
    protected array|Closure|null $constantFields = null;

    /** @var list<string>|null */
    protected ?array $cachedBoundKeys = null;

    /** @var array<string, string>|Closure(): array<string, string> */
    protected array|Closure $warnings = [];

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
        return JsonSchema::fromArray($this->evaluate($this->jsonSchema))->toMappingItems();
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

    /**
     * @param array<Component>|Closure(): array<Component> $fields
     */
    public function constants(array|Closure $fields): static
    {
        $this->constantFields = $fields;

        return $this;
    }

    public function hasConstants(): bool
    {
        return $this->constantFields !== null;
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
        if (isset($bindings[$key])) {
            return $bindings[$key];
        }

        /** @var Schema $schema The default child schema always exists. */
        $schema = $this->getChildSchema();

        $schema = $schema
            ->getClone()
            ->statePath('bindings.' . $key)
            ->inlineLabel(false);

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

    /** @return array<string, mixed> */
    public function getBindingValues(): array
    {
        return $this->getDehydratedBindings($this->getBindingState());
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
        /** @var array{sources?: array<string, array<string, mixed>>} $state */
        $state = $this->getRawState() ?? [];
        $sources = $this->encodeSourceSettings($state['sources'] ?? []);
        $bound = array_fill_keys(
            array_filter(array_keys($bindings), fn (string $key) => !self::isConstantKey($key)),
            true,
        );
        $this->rawState(['sources' => $sources, 'bindings' => $bindings, 'bound' => $bound, 'previews' => []]);
        $this->clearCachedChildSchemas();
        $this->fillSourceSettingsDefaults($sources);

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);
            if (isset($bound[$key])) {
                /** @var Schema $schema The default child schema always exists. */
                $schema = $this->getChildSchema();
                $schema
                    ->getClone()
                    ->statePath('previews.' . $key)
                    ->inlineLabel(false)
                    ->fill();
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

        foreach ($this->cachedBoundKeys as $key) {
            /** @var Schema $schema The default child schema always exists. */
            $schema = self::isConstantKey($key) ? $this->getConstantSchema() : $this->getChildSchema();
            $bindings[$key] = $schema
                ->statePath('bindings.' . $key)
                ->inlineLabel(false)
                ->getClone();
        }

        return $bindings;
    }

    protected function areCachedDefaultChildSchemasFresh(): bool
    {
        return $this->cachedBoundKeys === $this->getBoundKeys() && $this->areSourceSettingsSchemasFresh();
    }

    private function getConstantSchema(): Schema
    {
        /** @var array<Component> $fields */
        $fields = $this->evaluate($this->constantFields ?? []);

        return $this->makeChildSchema('constants')->components($fields);
    }

    public function getBindAction(): Action
    {
        return Action::make('bind')
            ->label(__('phpinnacle-ferry::resources.binding.bind'))
            ->icon('heroicon-m-link')
            ->iconButton()
            ->size('lg')
            ->tooltip(__('phpinnacle-ferry::resources.binding.bind'))
            ->alpineClickHandler(function (array $arguments) {
                /** @var array{source: string} $arguments */
                return 'bind(' . Js::from($this->getBindingKey($arguments['source']))->toHtml() . ')';
            })
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->bindSource($arguments));
    }

    public function getUnbindAction(): Action
    {
        return Action::make('unbind')
            ->label(__('phpinnacle-ferry::resources.binding.unbind'))
            ->alpineClickHandler(function (array $arguments) {
                /** @var array{source: string} $arguments */
                return 'unbind(' . Js::from($this->getBindingKey($arguments['source']))->toHtml() . ')';
            })
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->unbindSource($arguments));
    }

    public function getAddConstantAction(): Action
    {
        return Action::make('addConstant')
            ->label(__('phpinnacle-ferry::resources.binding.add_constant'))
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->button()
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (self $component) => $component->addConstant());
    }

    public function getRemoveConstantAction(): Action
    {
        return Action::make('removeConstant')
            ->label(__('phpinnacle-ferry::resources.binding.remove_constant'))
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->iconButton()
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->removeConstant($arguments));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([]);
        $this->default(['sources' => [], 'bindings' => []]);
        $this->rules(fn (self $component) => ['bail', 'array', $component->validateBindings(...)]);
        $this->registerActions([
            fn (self $component) => $component->getBindAction(),
            fn (self $component) => $component->getUnbindAction(),
            fn (self $component) => $component->getAddConstantAction(),
            fn (self $component) => $component->getRemoveConstantAction(),
        ]);
        $this->mutateDehydratedStateUsing($this->dehydrateBindings(...));
    }

    /**
     * @param array{sources?: array<string, array<string, mixed>>, bindings?: array<string, mixed>, bound?: array<string, bool>, previews?: array<string, mixed>}|null $state
     * @return array{sources: array<string, array<string, mixed>>, bindings: array<string, mixed>}
     */
    private function dehydrateBindings(?array $state): array
    {
        return [
            'sources' => $this->decodeSourceSettings($state['sources'] ?? []),
            'bindings' => $this->getDehydratedBindings($state['bindings'] ?? []),
        ];
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
        if ($this->isDisabled()) {
            return;
        }

        $key = self::CONSTANT_PREFIX . bin2hex(random_bytes(6));
        $bindings = $this->getBindingState();
        $bindings[$key] = [];
        $this->writeBindingState($bindings);
        $this->getBindings()[$key]->fill();
        $this->callAfterStateUpdated();
    }

    /** @param array<array-key, mixed> $arguments */
    private function removeConstant(array $arguments): void
    {
        if ($this->isDisabled()) {
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

        $state = $this->getBindingState();

        foreach ($state as $source => $binding) {
            $data = [];

            if ($this->hasConstants() && is_array($binding)) {
                $bindings[self::CONSTANT_PREFIX . bin2hex((string) $source)] = [
                    'column' => (string) $source,
                    ...$binding,
                ];

                continue;
            }

            if ($simple !== null) {
                data_set($data, $simple->getName(), $binding);
            } else {
                $data = $binding;
            }

            $bindings[$this->getBindingKey((string) $source)] = $data;
        }

        return $bindings;
    }

    /**
     * @param array<array-key, mixed> $state
     * @return array<string, mixed>
     */
    private function getDehydratedBindings(array $state): array
    {
        $bindings = [];
        $simple = $this->getSimpleField();
        $boundKeys = $this->getBoundKeys();

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);

            if (!in_array($key, $boundKeys, true) || !array_key_exists($key, $state)) {
                continue;
            }

            $bindings[$source['id']] = $simple !== null ? data_get($state[$key], $simple->getName()) : $state[$key];
        }

        foreach ($state as $key => $data) {
            if (self::isConstantKey((string) $key)) {
                /** @var array{column: string} $data */
                $bindings[$data['column']] = Arr::except($data, 'column');
            }
        }

        return $bindings;
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

        if ($this->hasConstants()) {
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
        $outputKeys = array_keys(array_filter($sourceKeys, fn (string $key) => $bound[$key] ?? false));
        $simple = $this->getSimpleField();

        foreach ($value as $key => $binding) {
            $isConstant = $this->hasConstants() && self::isConstantKey((string) $key);

            if (!$isConstant && !in_array($key, $keys, true)) {
                $fail('The :attribute field contains an unknown source.')->translate();

                return;
            }

            if (!is_array($binding)) {
                $fail('The :attribute field contains an invalid binding value.')->translate();

                return;
            }

            if ($isConstant) {
                $validator = Validator::make($binding, [
                    'column' => ['required', 'string', Rule::notIn($outputKeys)],
                ]);
                if ($validator->fails()) {
                    $fail($validator->errors()->first())->translate();

                    return;
                }
                /** @var array{column: string} $constant */
                $constant = $validator->validated();
                $outputKeys[] = $constant['column'];
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
