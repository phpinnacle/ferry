<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Forms\Concerns\HasMappingItems;

/** @phpstan-import-type MappingItem from HasMappingItems */
class FieldBinding extends Field
{
    use HasMappingItems;

    protected string $view = 'phpinnacle-ferry::forms.field-binding';

    /** @var array<array-key, string|object>|Closure(): array<array-key, string|object> */
    protected array|Closure $sources = [];

    /** @var string|Closure(): string */
    protected string|Closure $sourceLabel = 'Source';

    /** @var string|Closure(): string */
    protected string|Closure $targetLabel = 'Binding';

    /** @var Field|Closure(): Field|null */
    protected Field|Closure|null $simpleField = null;

    /** @var list<string>|null */
    protected ?array $cachedBoundSourceKeys = null;

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

    /** @param array<array-key, string|object>|Closure $sources */
    public function options(array|Closure $sources): static
    {
        $this->sources = $sources;

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
        return $this->normalizeItems($this->evaluate($this->sources), [], []);
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

    public function getBindingKey(string $source): string
    {
        return 'source_' . bin2hex($source);
    }

    /** @return array<Schema> */
    public function getBindings(): array
    {
        return $this->getCachedDefaultChildSchemas();
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
        $this->rawState($bindings);
        $this->clearCachedChildSchemas();

        if ($hydratedDefaultState !== null) {
            $defaults = $hydratedDefaultState;
            Arr::set($defaults, $this->getStatePath(), $bindings);
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
        $this->cachedBoundSourceKeys = $this->getBoundSourceKeys();
        $bindings = [];

        foreach ($this->cachedBoundSourceKeys as $key) {
            /** @var Schema $schema The default child schema always exists. */
            $schema = $this->getChildSchema();
            $bindings[$key] = $schema
                ->statePath($key)
                ->inlineLabel(false)
                ->getClone();
        }

        return $bindings;
    }

    protected function areCachedDefaultChildSchemasFresh(): bool
    {
        return $this->cachedBoundSourceKeys === $this->getBoundSourceKeys();
    }

    public function getBindAction(): Action
    {
        return Action::make('bind')
            ->label(__('phpinnacle-ferry::resources.binding.bind'))
            ->icon('heroicon-m-plus')
            ->button()
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->bindSource($arguments));
    }

    public function getUnbindAction(): Action
    {
        return Action::make('unbind')
            ->label(__('phpinnacle-ferry::resources.binding.unbind'))
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->iconButton()
            ->disabled(fn (self $component) => $component->isDisabled())
            ->action(fn (array $arguments, self $component) => $component->unbindSource($arguments));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->rules(fn (self $component) => ['array', $component->validateBindings(...)]);
        $this->registerActions([
            fn (self $component) => $component->getBindAction(),
            fn (self $component) => $component->getUnbindAction(),
        ]);
        $this->mutateDehydratedStateUsing(fn (self $component, ?array $state) => $component->getDehydratedBindings(
            $state ?? [],
        ));
    }

    /** @param array<array-key, mixed> $arguments */
    private function bindSource(array $arguments): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $key = $this->getBindingKey($this->validateSource($arguments));
        /** @var array<array-key, mixed> $bindings */
        $bindings = $this->getRawState() ?? [];

        if (array_key_exists($key, $bindings)) {
            return;
        }

        $bindings[$key] = [];
        $this->rawState($bindings);
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
        /** @var array<array-key, mixed> $bindings */
        $bindings = $this->getRawState() ?? [];
        unset($bindings[$key]);
        $this->rawState($bindings);
        $this->callAfterStateUpdated();
    }

    /** @return array<string, mixed> */
    private function getHydratedBindings(): array
    {
        $bindings = [];
        $simple = $this->getSimpleField();

        /** @var array<array-key, string|array<string, mixed>> $state */
        $state = $this->getRawState() ?? [];

        foreach ($state as $source => $binding) {
            $data = [];

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
     * @return array<array-key, mixed>
     */
    private function getDehydratedBindings(array $state): array
    {
        $bindings = [];
        $simple = $this->getSimpleField();

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);

            if (!array_key_exists($key, $state)) {
                continue;
            }

            $bindings[$source['id']] = $simple !== null ? data_get($state[$key], $simple->getName()) : $state[$key];
        }

        return $bindings;
    }

    /** @return list<string> */
    private function getBoundSourceKeys(): array
    {
        $state = $this->getRawState();

        if (!is_array($state)) {
            return [];
        }

        $keys = [];

        foreach ($this->getSources() as $source) {
            $key = $this->getBindingKey($source['id']);

            if (is_array($state[$key] ?? null)) {
                $keys[] = $key;
            }
        }

        return $keys;
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

        $keys = array_map(fn (array $source) => $this->getBindingKey($source['id']), $this->getSources());
        $simple = $this->getSimpleField();

        foreach ($value as $key => $binding) {
            if (!in_array($key, $keys, true)) {
                $fail('The :attribute field contains an unknown source.')->translate();

                return;
            }

            if (!is_array($binding)) {
                $fail('The :attribute field contains an invalid binding value.')->translate();

                return;
            }

            if ($simple !== null) {
                $destination = data_get($binding, $simple->getName());

                if (!is_string($destination) || $destination === '') {
                    $fail('The :attribute field must bind each source to a non-empty string.')->translate();

                    return;
                }
            }
        }
    }
}
