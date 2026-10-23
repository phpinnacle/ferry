<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Concerns\CanBeCompact;
use Filament\Schemas\Schema;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Forms\Concerns\HasSourceSettings;
use PHPinnacle\Ferry\Forms\Concerns\Searchable;

/**
 * @phpstan-import-type MappingItem from JsonSchema
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-type SourceEntry array{kind: 'source', source: string}&array<string, mixed>
 * @phpstan-type ValueEntry array{kind: 'value', value: mixed}
 * @phpstan-type Entry SourceEntry|ValueEntry
 * @phpstan-type Targets array<string, Entry|non-empty-list<SourceEntry>>
 * @phpstan-type SourceState array{kind: 'source', source: string, target: string|null}&array<string, mixed>
 * @phpstan-type StaticState array{kind: 'static', target: string, static: mixed}
 * @phpstan-type State list<SourceState|StaticState>
 */
// @mago-expect lint:too-many-properties
class FieldMapping extends Field
{
    use CanBeCompact;
    use CanBeContained;
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

    /** @var array<string, Closure(): Field> */
    protected array $staticForms = [];

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

    /** @param array<string, Closure(): Field> $forms */
    public function staticForms(array $forms): static
    {
        $this->staticForms = $forms;
        $this->schema([]);

        return $this;
    }

    public function hasStaticForms(): bool
    {
        return $this->staticForms !== [];
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
        $type = $this->getStaticFormType($target);
        if ($type === null) {
            return null;
        }

        /** @var Field $field */
        $field = $this->evaluate($this->staticForms[$type], ['target' => $target, 'type' => $type]);

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
        $sources = [];
        $targets = [];
        foreach ($state as $entry) {
            if ($entry['kind'] === 'source') {
                /** @var array<string, mixed> $settings */
                $settings = Arr::except($entry, ['kind', 'source', 'target']);
                if ($settings !== []) {
                    $sources[$entry['source']] = $settings;
                }
            }
        }
        $this->rawState(['sources' => $this->encodeSourceSettings($sources), 'targets' => []]);
        $modes = $this->getConnectionModes();
        foreach ($state as $entry) {
            if ($entry['target'] === null) {
                continue;
            }
            $target = $entry['target'];
            if ($entry['kind'] === 'static') {
                $targets[$target] = ['kind' => 'value', 'value' => $entry['static']];
            } elseif (($modes[$entry['source']][$target] ?? null) === 'item') {
                $targets[$target][] = ['kind' => 'source', 'source' => $entry['source']];
            } else {
                $targets[$target] = ['kind' => 'source', 'source' => $entry['source']];
            }
        }
        $encoded = [
            'sources' => $this->encodeSourceSettings($sources),
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
     * @param array{sources?: array<string, array<string, mixed>>, targets?: Targets}|null $state
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
                    $rows[] =
                        ['kind' => 'source', 'source' => $source, 'target' => (string) $target]
                        + ($sources[$source] ?? []);
                    $used[$source] = true;
                }
            }
        }
        foreach ($this->getSources() as $source) {
            $id = $source['id'];
            if (!isset($used[$id])) {
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
        return $this->evaluate($this->sourceSchema);
    }

    /** @return Definition */
    public function getTargetSchema(): array
    {
        return $this->evaluate($this->targetSchema);
    }

    /** @return Definition */
    public function getOutputSchema(): array
    {
        return $this->evaluate($this->outputSchema ?? $this->sourceSchema);
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
        $this->required(fn () => $this->getRequiredTargets() !== []);
        $this->mutateDehydratedStateUsing($this->dehydrateMapping(...));
        $this->rules(fn () => [
            'bail',
            Rule::array(['sources', 'targets']),
            $this->validateMapping(...),
        ]);
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateMapping(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            return;
        }

        $validator = Validator::make($value, [
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
                        || $this->getStaticFormType((string) $target) === null
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
                && $this->getStaticFormType($target) !== null
            );
        }));
    }

    private static function stateKey(string $target): string
    {
        return 'target_' . bin2hex($target);
    }

    private function getStaticFormType(string $target): ?string
    {
        if ($this->staticForms === []) {
            return null;
        }

        $property = JsonSchema::fromArray($this->getTargetSchema())->properties()[$target];
        $types = array_values(array_diff($property->types(), ['null']));

        return count($types) === 1 && array_key_exists($types[0], $this->staticForms) ? $types[0] : null;
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
