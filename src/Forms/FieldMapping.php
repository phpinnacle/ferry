<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Concerns\CanBeCompact;
use Filament\Schemas\Schema;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Forms\Concerns\HasColumnSpans;
use PHPinnacle\Ferry\Forms\Concerns\HasSourceSettings;
use PHPinnacle\Ferry\Forms\Concerns\Searchable;

/**
 * @phpstan-import-type MappingItem from JsonSchema
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-type SourceEntry array{kind: 'source', source: string}
 * @phpstan-type ValueEntry array{kind: 'value', value: mixed}
 * @phpstan-type Entry SourceEntry|ValueEntry
 * @phpstan-type Targets array<string, Entry|non-empty-list<SourceEntry>>
 * @phpstan-type SourceState array{kind: 'source', source: string, target: string|null}&array<string, mixed>
 * @phpstan-type StaticState array{kind: 'static', target: string, static: mixed}&array<string, mixed>
 * @phpstan-type State list<SourceState|StaticState>
 */
// @mago-expect lint:too-many-properties
class FieldMapping extends Field
{
    use CanBeCompact;
    use CanBeContained;
    use HasColumnSpans;
    use HasSourceSettings;
    use Searchable;

    protected string $view = 'phpinnacle-ferry::forms.field-mapping';

    /** @var Definition|Closure(): Definition */
    protected array|Closure $sourceSchema = [];

    /** @var Definition|Closure(): Definition */
    protected array|Closure $targetSchema = [];

    /** @var Definition|Closure(): Definition|null */
    protected array|Closure|null $outputSchema = null;

    protected ?SchemaCompatibility $compatibility = null;

    /** @var Closure(JsonSchema, JsonSchema): bool|null */
    protected ?Closure $canConnectUsing = null;

    /** @var string|Closure(): string */
    protected string|Closure $sourceLabel = 'Source';

    /** @var string|Closure(): string */
    protected string|Closure $targetLabel = 'Destination';

    /** @var array<string, Closure> */
    protected array $staticForms = [];

    protected bool $hasStaticValues = false;

    /** @var bool|Closure(): bool */
    protected bool|Closure $isInverse = false;

    /** @var array<string, string>|Closure(): array<string, string> */
    protected array|Closure $sourceWarnings = [];

    /** @var array<string, string>|Closure(): array<string, string> */
    protected array|Closure $targetWarnings = [];

    /** @var list<string> */
    private array $cachedStaticTargets = [];

    /**
     * @param array<string, string>|Closure(): array<string, string> $source
     * @param array<string, string>|Closure(): array<string, string> $dest
     */
    public function warnings(array|Closure $source = [], array|Closure $dest = []): static
    {
        $this->sourceWarnings = $source;
        $this->targetWarnings = $dest;

        return $this;
    }

    /** @return array<string, string> */
    public function getSourceWarnings(): array
    {
        return $this->evaluate($this->sourceWarnings);
    }

    /** @return array<string, string> */
    public function getTargetWarnings(): array
    {
        return $this->evaluate($this->targetWarnings);
    }

    /** @return list<string> */
    public function getRequiredTargets(): array
    {
        return JsonSchema::fromArray($this->getTargetSchema())->required();
    }

    /** @return list<string> */
    public function getMultipleTargets(): array
    {
        return array_keys(array_filter(
            JsonSchema::fromArray($this->getTargetSchema())->properties(),
            fn (JsonSchema $property) => $property->items() !== null,
        ));
    }

    /** @param array<string, Closure> $forms Factories returning a Field named value or null when unavailable, with Filament-injected arguments. */
    public function staticForms(array $forms): static
    {
        $this->staticForms = $forms;
        $this->schema([]);

        return $this;
    }

    public function withBuiltinStaticForms(): static
    {
        $forms = $this->staticForms;
        foreach (['string', 'integer', 'number', 'boolean', 'array', 'null'] as $type) {
            $forms[$type] ??= fn (FieldMapping $component, string $target) => BuiltinStaticForms::make(
                JsonSchema::fromArray($component->getTargetSchema())->properties()[$target],
                $type,
                in_array($target, $component->getRequiredTargets(), true),
            );
        }

        return $this->staticForms($forms);
    }

    public function hasStaticForms(): bool
    {
        return !$this->hasStaticValues() && $this->staticForms !== [];
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

    /** @return array<string, array{static: mixed}> */
    public function getConstants(): array
    {
        if (!$this->hasStaticValues()) {
            return [];
        }

        /** @var array{constants?: array<string, array{static: mixed}>}|null $state */
        $state = $this->getRawState();

        return $state['constants'] ?? [];
    }

    public function getConstantValueSchema(string $source): Schema
    {
        return $this
            ->makeChildSchema('constant_' . $source)
            ->statePath('constants.' . $source)
            ->components([
                TextInput::make('static')
                    ->label(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->hiddenLabel()
                    ->inlineLabel(false)
                    ->placeholder(__('phpinnacle-ferry::resources.sync.fields.constant_value'))
                    ->default('')
                    ->required(fn () => $this->isSourceUsed($source))
                    ->rule(fn () => $this->validateStaticValue(...)),
            ]);
    }

    /** @return array<Component> */
    protected function getSourceSettingsComponents(string $source): array
    {
        return $this->evaluate($this->sourceSettingsSchema, [
            'source' => $source,
            'inputSchema' => array_key_exists($source, $this->getConstants())
                ? fn () => fn () => JsonSchema::fromArray($this->getSourceSchema())->properties()[$source]
                : null,
        ]);
    }

    /** @return array<string, string> */
    public function getStateKeys(): array
    {
        $keys = [];
        foreach ($this->getTargets() as $target) {
            $keys[$target['id']] = self::stateKey($target['id']);
        }

        return $keys;
    }

    public function getStaticFormSchema(string $target): ?Schema
    {
        $field = $this->makeStaticField($target);
        if ($field === null) {
            return null;
        }

        return $this
            ->makeChildSchema(self::stateKey($target))
            ->statePath('targets.' . self::stateKey($target))
            ->components([
                Hidden::make('kind')->default('value'),
                $field,
            ]);
    }

    /** @return array<string, mixed> */
    public function getStaticDefaults(): array
    {
        if (!$this->hasStaticForms()) {
            return [];
        }

        $defaults = [];
        foreach (array_column($this->getTargets(), 'id') as $target) {
            $schema = $this->getStaticFormSchema($target);
            if ($schema !== null) {
                /** @var Field $field */
                $field = $schema->getComponent('value');
                $defaults[$target] = $field->getDefaultState();
            }
        }

        return $defaults;
    }

    /** @return array<Schema> */
    public function getDefaultChildSchemas(): array
    {
        $this->cachedStaticTargets = $this->getStaticTargets();
        $schemas = $this->makeSourceSettingsSchemas();
        foreach (array_keys($this->getConstants()) as $source) {
            $schemas['constant_' . $source] = $this->getConstantValueSchema($source);
        }
        foreach ($this->cachedStaticTargets as $target) {
            $schema = $this->getStaticFormSchema($target);
            if ($schema !== null) {
                $schemas[self::stateKey($target)] = $schema;
            }
        }

        return $schemas;
    }

    protected function areCachedDefaultChildSchemasFresh(): bool
    {
        return $this->cachedStaticTargets === $this->getStaticTargets() && $this->areSourceSettingsSchemasFresh();
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
        /** @var State $state */
        $state = $this->getRawState() ?? [];
        $sourceState = $this->sourceStateFromMappings($state);
        $this->rawState([
            'constants' => $sourceState['constants'],
            'sources' => $this->encodeSourceSettings($sourceState['sources']),
            'targets' => [],
        ]);
        $modes = $this->getConnectionModes();
        $constantSources = array_keys($sourceState['constants']);
        $constantIndex = 0;
        $targets = [];
        foreach ($state as $entry) {
            if ($entry['target'] === null) {
                continue;
            }
            $target = $entry['target'];
            if ($entry['kind'] === 'static' && !$this->hasStaticValues()) {
                $targets[$target] = ['kind' => 'value', 'value' => $entry['static']];

                continue;
            }
            $source = $entry['kind'] === 'static' ? $constantSources[$constantIndex++] : $entry['source'];
            if (($modes[$source][$target] ?? null) === 'item') {
                $targets[$target][] = ['kind' => 'source', 'source' => $source];
            } else {
                $targets[$target] = ['kind' => 'source', 'source' => $source];
            }
        }
        $encoded = [
            'constants' => $sourceState['constants'],
            'sources' => $this->encodeSourceSettings($sourceState['sources']),
            'targets' => self::rekey($targets, $this->getStateKeys()),
        ];
        foreach ($this->getStaticDefaults() as $target => $value) {
            $key = self::stateKey((string) $target);
            if (!array_key_exists($key, $encoded['targets'])) {
                $encoded['targets'][$key] = ['kind' => 'value', 'value' => $value];
            }
        }
        $this->rawState($encoded);
        $this->clearCachedChildSchemas();
        $this->fillSourceSettingsDefaults($encoded['sources']);
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

    /**
     * @param State $state
     * @return array{constants: array<string, array{static: mixed}>, sources: array<string, array<string, mixed>>}
     */
    private function sourceStateFromMappings(array $state): array
    {
        $sources = [];
        $constants = [];
        foreach ($state as $entry) {
            if ($entry['kind'] === 'source') {
                $source = $entry['source'];
                /** @var array<string, mixed> $settings */
                $settings = Arr::except($entry, ['kind', 'source', 'target']);
            } elseif ($this->hasStaticValues()) {
                $source = $this->nextConstantName($constants);
                $constants[$source] = ['static' => $entry['static']];
                $settings = array_diff_key($entry, ['kind' => true, 'target' => true, 'static' => true]);
            } else {
                continue;
            }
            if ($settings !== []) {
                $sources[$source] = $settings;
            }
        }

        return ['constants' => $constants, 'sources' => $sources];
    }

    /**
     * @param array{constants?: array<string, array{static: mixed}>, sources?: array<string, array<string, mixed>>, targets?: Targets}|null $state
     * @return array<array-key, mixed>
     */
    private function dehydrateMapping(?array $state): array
    {
        $sources = $this->decodeSourceSettings($state['sources'] ?? []);
        /** @var Targets $targets */
        $targets = self::rekey($state['targets'] ?? [], array_flip($this->getStateKeys()));
        $rows = [];
        $used = [];
        foreach ($targets as $target => $mapped) {
            /** @var list<Entry> $entries */
            $entries = array_is_list($mapped) ? $mapped : [$mapped];
            foreach ($entries as $entry) {
                if ($entry['kind'] === 'value') {
                    $rows[] = ['kind' => 'static', 'target' => (string) $target, 'static' => $entry['value']];
                } else {
                    $source = $entry['source'];
                    $rows[] = array_key_exists($source, $state['constants'] ?? [])
                        ? [
                            'kind' => 'static',
                            'target' => (string) $target,
                            'static' => $state['constants'][$source]['static'],
                        ]
                        + ($sources[$source] ?? [])
                        : ['kind' => 'source', 'source' => $source, 'target' => (string) $target]
                        + ($sources[$source] ?? []);
                    $used[$source] = true;
                }
            }
        }
        foreach ($this->getSources() as $source) {
            $id = $source['id'];
            if (!($used[$id] ?? false) && !array_key_exists($id, $state['constants'] ?? [])) {
                $rows[] = ['kind' => 'source', 'source' => $id, 'target' => null] + ($sources[$id] ?? []);
            }
        }

        return $rows;
    }

    /** @param bool|Closure(): bool $condition */
    public function inverse(bool|Closure $condition = true): static
    {
        $this->isInverse = $condition;

        return $this;
    }

    public function isInverse(): bool
    {
        return $this->evaluate($this->isInverse);
    }

    /**
     * @param Definition|Closure $source
     * @param Definition|Closure $dest
     */
    public function jsonSchema(array|Closure $source = [], array|Closure $dest = []): static
    {
        $this->sourceSchema = $source;
        $this->targetSchema = $dest;

        return $this;
    }

    /** @param Definition|Closure $schema */
    public function outputSchema(array|Closure $schema): static
    {
        $this->outputSchema = $schema;

        return $this;
    }

    /** @return Definition */
    public function getSourceSchema(): array
    {
        $schema = $this->evaluate($this->sourceSchema);
        foreach ($this->getConstants() as $source => $constant) {
            $schema['properties'][$source] = [
                'title' => Str::headline($source),
                'type' => match (true) {
                    is_bool($constant['static']) => 'boolean',
                    is_int($constant['static']) => 'integer',
                    is_float($constant['static']) => 'number',
                    default => 'string',
                },
            ];
        }

        return $schema;
    }

    /** @return Definition */
    public function getTargetSchema(): array
    {
        return $this->evaluate($this->targetSchema);
    }

    /** @return Definition */
    public function getOutputSchema(): array
    {
        return $this->outputSchema === null ? $this->getSourceSchema() : $this->evaluate($this->outputSchema);
    }

    public function compatibility(SchemaCompatibility $compatibility): static
    {
        $this->compatibility = $compatibility;

        return $this;
    }

    /** @param Closure(JsonSchema, JsonSchema): bool $callback */
    public function canConnectUsing(Closure $callback): static
    {
        $this->canConnectUsing = $callback;

        return $this;
    }

    private function canConnect(JsonSchema $source, JsonSchema $target): bool
    {
        return (
            $source->compatible($target, $this->compatibility)
            && ($this->canConnectUsing === null || ($this->canConnectUsing)($source, $target))
        );
    }

    /** @return array<string, array<string, 'whole'|'item'>> */
    public function getConnectionModes(): array
    {
        $modes = [];
        $outputs = JsonSchema::fromArray($this->getOutputSchema())->properties();
        $targets = JsonSchema::fromArray($this->getTargetSchema())->properties();
        foreach (array_keys(JsonSchema::fromArray($this->getSourceSchema())->properties()) as $source) {
            $output = $outputs[$source] ?? JsonSchema::fromArray([]);
            foreach ($targets as $target => $destination) {
                $items = $destination->items();
                if (
                    ($items === null
                    || $output->items() !== null)
                    && $this->canConnect($output, $destination)
                ) {
                    $modes[$source][$target] = 'whole';
                } elseif ($items !== null && $this->canConnect($output, $items)) {
                    $modes[$source][$target] = 'item';
                }
            }
        }

        return $modes;
    }

    /**
     * @param string|Closure(): string $source
     * @param string|Closure(): string $dest
     */
    public function labels(string|Closure $source = 'Source', string|Closure $dest = 'Destination'): static
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
        return JsonSchema::fromArray($this->getSourceSchema())->toMappingItems();
    }

    /** @return list<MappingItem> */
    public function getTargets(): array
    {
        return JsonSchema::fromArray($this->getTargetSchema())->toMappingItems();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([]);
        $this->default([]);
        $this->partiallyRenderAfterStateUpdated();
        $this->required(fn () => $this->getRequiredTargets() !== []);
        $this->mutateDehydratedStateUsing($this->dehydrateMapping(...));
        $this->rules(fn () => [
            'bail',
            Rule::array(['constants', 'sources', 'targets']),
            $this->validateMapping(...),
        ]);
        $this->registerActions([
            fn (self $component) => $component->getAddConstantAction(),
            fn (self $component) => $component->getRemoveConstantAction(),
        ]);
    }

    public function getAddConstantAction(): Action
    {
        return Action::make('addConstant')
            ->label(__('phpinnacle-ferry::resources.binding.add_constant'))
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->button()
            ->disabled(fn () => $this->isDisabled() || !$this->hasStaticValues())
            ->action(function () {
                if ($this->isDisabled() || !$this->hasStaticValues()) {
                    return;
                }
                /** @var array{constants: array<string, array{static: mixed}>, sources: array<string, array<string, mixed>>, targets: Targets} $state */
                $state = $this->getRawState();
                $source = $this->nextConstantName($this->getConstants());
                $state['constants'][$source] = ['static' => ''];
                $this->rawState($state);
                $this->clearCachedChildSchemas();
                $this->getChildSchemas()['constant_' . $source]->fill();
                $this->fillSourceSettingsDefaults($state['sources']);
                $this->callAfterStateUpdated();
            });
    }

    public function getRemoveConstantAction(): Action
    {
        return Action::make('removeConstant')
            ->label(__('phpinnacle-ferry::resources.binding.remove_constant'))
            ->icon('heroicon-m-trash')
            ->color('gray')
            ->iconButton()
            ->disabled(fn () => $this->isDisabled() || !$this->hasStaticValues())
            ->action(function (array $arguments) {
                if ($this->isDisabled() || !$this->hasStaticValues()) {
                    return;
                }
                /** @var array{constant: string} $validated */
                $validated = Validator::make($arguments, [
                    'constant' => ['required', 'string', Rule::in(array_keys($this->getConstants()))],
                ])->validate();
                $source = $validated['constant'];
                if ($this->isSourceUsed($source)) {
                    return;
                }
                /** @var array{constants: array<string, array{static: mixed}>, sources: array<string, array<string, mixed>>, targets: Targets} $state */
                $state = $this->getRawState();
                unset($state['constants'][$source], $state['sources'][$this->getSourceStateKeys()[$source]]);
                $this->rawState($state);
                $this->clearCachedChildSchemas();
                $this->callAfterStateUpdated();
            });
    }

    /** @param array<string, array{static: mixed}> $constants */
    private function nextConstantName(array $constants): string
    {
        $properties = $this->evaluate($this->sourceSchema)['properties'] ?? [];
        $number = 1;
        while (
            array_key_exists('static_' . $number, $properties)
            || array_key_exists('static_' . $number, $constants)
        ) {
            $number++;
        }

        return 'static_' . $number;
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateStaticValue(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_scalar($value)) {
            $fail('The :attribute field must be a scalar value.')->translate();
        }
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateConstantNames(string $attribute, mixed $constants, Closure $fail): void
    {
        if (!is_array($constants)) {
            return;
        }
        $properties = $this->evaluate($this->sourceSchema)['properties'] ?? [];
        foreach (array_keys($constants) as $source) {
            if (!preg_match('/^static_[1-9][0-9]*$/', (string) $source) || array_key_exists($source, $properties)) {
                $fail('The :attribute field contains an invalid static source.')->translate();

                return;
            }
        }
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateMapping(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            return;
        }

        $validator = Validator::make($value, [
            'constants' => [$this->hasStaticValues() ? 'array' : 'prohibited', $this->validateConstantNames(...)],
            'constants.*' => ['array:static'],
            'constants.*.static' => ['present'],
            'sources' => ['array', Rule::array(array_values($this->getSourceStateKeys()))],
            'sources.*' => ['array'],
            'targets' => ['array', Rule::array(array_values($this->getStateKeys()))],
        ]);
        if ($validator->fails()) {
            $fail($validator->errors()->first())->translate();

            return;
        }
        /** @var array{targets?: array<string, mixed>} $validated */
        $validated = $validator->validated();
        $value = self::rekey($validated['targets'] ?? [], array_flip($this->getStateKeys()));

        $modes = $this->getConnectionModes();
        $targets = array_column($this->getTargets(), null, 'id');
        $multiple = $this->getMultipleTargets();
        $usedSources = [];
        $usedTargets = [];

        foreach ($value as $target => $mapped) {
            $collection = is_array($mapped) && array_is_list($mapped);
            if (!is_array($mapped) || $mapped === [] || $collection && !in_array((string) $target, $multiple, true)) {
                $fail('The :attribute field contains an invalid mapping value.')->translate();

                return;
            }
            foreach ($collection ? $mapped : [$mapped] as $entry) {
                if (!is_array($entry) || !$this->isValidEntry($entry)) {
                    $fail('The :attribute field contains an invalid mapping value.')->translate();

                    return;
                }
                if ($entry['kind'] === 'value') {
                    if (
                        $collection
                        || $this->makeStaticField((string) $target) === null
                        && (!is_scalar($entry['value'])
                        || $entry['value'] === '')
                    ) {
                        $fail('The :attribute field contains an invalid mapping value.')->translate();

                        return;
                    }
                } else {
                    $source = $entry['source'];
                    $mode = $modes[$source][$target] ?? null;
                    if ($mode === null || $collection !== ($mode === 'item')) {
                        $fail(
                            'The :attribute field contains incompatible source and destination schemas.',
                        )->translate();

                        return;
                    }
                    if ($usedSources[$source] ?? false) {
                        $fail('The :attribute field reuses a source that only permits one connection.')->translate();

                        return;
                    }
                    $usedSources[$source] = true;
                }
                $usedTargets[$target] = true;
            }
        }

        foreach ($this->getRequiredTargets() as $target) {
            if (!($usedTargets[$target] ?? false)) {
                $fail(
                    'Connect the required destination: ' . strip_tags($targets[$target]['label'] ?? $target) . '.',
                )->translate();
            }
        }
    }

    /**
     * @param array<array-key, mixed> $entry
     * @phpstan-assert-if-true Entry $entry
     */
    private function isValidEntry(array $entry): bool
    {
        $kind = $entry['kind'] ?? null;
        if (!in_array($kind, ['source', 'value'], true)) {
            return false;
        }

        return Validator::make(['entry' => $entry], [
            'entry' => ['array:kind,' . $kind],
            'entry.' . $kind => $kind === 'source' ? ['required', 'string'] : ['present'],
        ])->passes();
    }

    public function isSourceUsed(string $source): bool
    {
        /** @var array{targets?: mixed}|null $state */
        $state = $this->getRawState();
        $targets = $state['targets'] ?? [];
        if (!is_array($targets)) {
            return false;
        }
        foreach ($targets as $mapped) {
            if (!is_array($mapped)) {
                continue;
            }
            foreach (array_is_list($mapped) ? $mapped : [$mapped] as $entry) {
                if (
                    is_array($entry)
                    && ($entry['kind'] ?? null) === 'source'
                    && ($entry['source'] ?? null) === $source
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function getStaticTargets(): array
    {
        /** @var array{targets?: array<string, mixed>} $rawState */
        $rawState = $this->getRawState() ?? [];
        $state = $rawState['targets'] ?? null;
        if (!is_array($state) || !$this->hasStaticForms()) {
            return [];
        }

        return array_values(array_filter(array_column($this->getTargets(), 'id'), function (string $target) use (
            $state,
        ) {
            $key = self::stateKey($target);

            return (
                (
                    !array_key_exists($key, $state)
                    || is_array($state[$key])
                    && ($state[$key]['kind'] ?? null) === 'value'
                )
                && $this->makeStaticField($target) !== null
            );
        }));
    }

    private static function stateKey(string $target): string
    {
        return 'target_' . bin2hex($target);
    }

    private function makeStaticField(string $target): ?Field
    {
        if (!$this->hasStaticForms()) {
            return null;
        }

        $property = JsonSchema::fromArray($this->getTargetSchema())->properties()[$target];
        $type = $property->singleType();

        if ($type === null || !array_key_exists($type, $this->staticForms)) {
            return null;
        }

        /** @var Field|null */
        return $this->evaluate($this->staticForms[$type], ['target' => $target, 'type' => $type]);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $keys
     * @return array<string, mixed>
     */
    private static function rekey(array $state, array $keys): array
    {
        return array_combine(
            array_map(fn (int|string $key) => $keys[$key] ?? $key, array_keys($state)),
            array_values($state),
        );
    }
}
