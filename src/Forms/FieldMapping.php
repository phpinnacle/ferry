<?php

namespace PHPinnacle\Ferry\Forms;

use BackedEnum;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Translation\PotentiallyTranslatedString;
use Stringable;
use UnitEnum;

/**
 * @phpstan-type MappingBadge array{label: string, color: string}
 * @phpstan-type MappingItem array{
 *     id: string,
 *     label: string|Htmlable,
 *     icon: string|BackedEnum|Htmlable|null,
 *     description: string|Htmlable|null,
 *     type: string|null,
 *     required: bool,
 *     badges: list<MappingBadge>
 * }
 */
// @mago-expect lint:too-many-properties
class FieldMapping extends Field
{
    protected string $view = 'phpinnacle-ferry::forms.field-mapping';

    /** @var array<array-key, string|object>|Closure(): array<array-key, string|object> */
    protected array|Closure $sources = [];

    /** @var array<array-key, string|object>|Closure(): array<array-key, string|object> */
    protected array|Closure $targets = [];

    /** @var string|Closure(): string */
    protected string|Closure $sourceLabel = 'Source';

    /** @var string|Closure(): string */
    protected string|Closure $targetLabel = 'Destination';

    /** @var list<string>|Closure(): list<string> */
    protected array|Closure $requiredTargets = [];

    /** @var list<string>|Closure(): list<string> */
    protected array|Closure $multipleTargets = [];

    /** @var bool|Closure(): bool */
    protected bool|Closure $isInverse = false;

    /** @var array<array-key, string>|Closure(): array<array-key, string> */
    protected array|Closure $sourceTypes = [];

    /** @var array<array-key, string>|Closure(): array<array-key, string> */
    protected array|Closure $targetTypes = [];

    /** @var array<array-key, list<string|array{label: string, color?: string}>>|Closure(): array<array-key, list<string|array{label: string, color?: string}>> */
    protected array|Closure $sourceBadges = [];

    /** @var array<array-key, list<string|array{label: string, color?: string}>>|Closure(): array<array-key, list<string|array{label: string, color?: string}>> */
    protected array|Closure $targetBadges = [];

    /** @param list<string>|Closure(): list<string> $targets */
    public function requiredTargets(array|Closure $targets): static
    {
        $this->requiredTargets = $targets;

        return $this;
    }

    /** @return list<string> */
    public function getRequiredTargets(): array
    {
        return $this->evaluate($this->requiredTargets);
    }

    /** @param list<string>|Closure(): list<string> $targets */
    public function multiple(array|Closure $targets): static
    {
        $this->multipleTargets = $targets;

        return $this;
    }

    /** @return list<string> */
    public function getMultipleTargets(): array
    {
        return $this->evaluate($this->multipleTargets);
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
     * @param  array<array-key, string>|Closure(): array<array-key, string>  $source
     * @param  array<array-key, string>|Closure(): array<array-key, string>  $dest
     */
    public function types(array|Closure $source = [], array|Closure $dest = []): static
    {
        $this->sourceTypes = $source;
        $this->targetTypes = $dest;

        return $this;
    }

    /**
     * @param  array<array-key, list<string|array{label: string, color?: string}>>|Closure(): array<array-key, list<string|array{label: string, color?: string}>>  $source
     * @param  array<array-key, list<string|array{label: string, color?: string}>>|Closure(): array<array-key, list<string|array{label: string, color?: string}>>  $dest
     */
    public function badges(array|Closure $source = [], array|Closure $dest = []): static
    {
        $this->sourceBadges = $source;
        $this->targetBadges = $dest;

        return $this;
    }

    /**
     * @param  array<array-key, string|object>|Closure(): array<array-key, string|object>  $source
     * @param  array<array-key, string|object>|Closure(): array<array-key, string|object>  $dest
     */
    public function options(array|Closure $source = [], array|Closure $dest = []): static
    {
        $this->sources = $source;
        $this->targets = $dest;

        return $this;
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
        return $this->normalizeItems(
            $this->evaluate($this->sources),
            $this->evaluate($this->sourceTypes),
            $this->evaluate($this->sourceBadges),
        );
    }

    /** @return list<MappingItem> */
    public function getTargets(): array
    {
        return $this->normalizeItems(
            $this->evaluate($this->targets),
            $this->evaluate($this->targetTypes),
            $this->evaluate($this->targetBadges),
            $this->getRequiredTargets(),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
        $this->required(fn () => $this->getRequiredTargets() !== []);
        $this->rules(fn () => [
            'array',
            $this->validateMapping(...),
        ]);
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateMapping(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            return;
        }

        $sources = array_column($this->getSources(), null, 'id');
        $targets = array_column($this->getTargets(), null, 'id');
        $multiple = $this->getMultipleTargets();
        $inverse = $this->isInverse();
        $usedSources = [];
        $usedTargets = [];

        foreach ($value as $id => $mapped) {
            $expectsList = !$inverse && in_array((string) $id, $multiple, true);
            $hasValidShape = $expectsList
                ? is_array($mapped) && array_is_list($mapped) && $mapped !== []
                : is_string($mapped);

            if (!$hasValidShape) {
                $fail('The :attribute field contains an invalid mapping value.')->translate();

                return;
            }

            foreach (is_array($mapped) ? $mapped : [$mapped] as $mappedId) {
                $source = $inverse ? (string) $id : $mappedId;
                $target = $inverse ? $mappedId : (string) $id;

                if (
                    !is_string($source)
                    || !is_string($target)
                    || !array_key_exists($source, $sources)
                    || !array_key_exists($target, $targets)
                ) {
                    $fail('The :attribute field contains an unknown source or destination.')->translate();

                    return;
                }

                $sourceType = $sources[$source]['type'];
                $targetType = $targets[$target]['type'];

                if ($sourceType !== null && $targetType !== null && $sourceType !== $targetType) {
                    $fail('The :attribute field contains incompatible source and destination types.')->translate();

                    return;
                }

                if ($usedSources[$source] ?? false) {
                    $fail('The :attribute field reuses a source that only permits one connection.')->translate();

                    return;
                }

                if (($usedTargets[$target] ?? false) && !in_array($target, $multiple, true)) {
                    $fail('The :attribute field reuses a destination that only permits one connection.')->translate();

                    return;
                }

                $usedSources[$source] = true;
                $usedTargets[$target] = true;
            }
        }

        foreach ($targets as $target) {
            if ($target['required'] && !($usedTargets[$target['id']] ?? false)) {
                $label = $target['label'] instanceof Htmlable ? $target['label']->toHtml() : $target['label'];
                $fail('Connect the required destination: ' . strip_tags($label) . '.')->translate();
            }
        }
    }

    /**
     * @param  array<array-key, string|object>  $items
     * @param  array<array-key, string>  $types
     * @param  array<array-key, list<string|array{label: string, color?: string}>>  $badges
     * @param  list<string>  $required
     * @return list<MappingItem>
     */
    private function normalizeItems(array $items, array $types, array $badges, array $required = []): array
    {
        $isList = array_is_list($items);
        $normalized = [];
        foreach ($items as $key => $item) {
            $id = (string) match (true) {
                !$isList => $key,
                is_string($item) => $item,
                $item instanceof BackedEnum => $item->value,
                $item instanceof UnitEnum => $item->name,
                default => $key,
            };

            $itemBadges = [];

            foreach ($badges[$id] ?? [] as $badge) {
                $itemBadges[] = is_string($badge)
                    ? ['label' => $badge, 'color' => 'gray']
                    : [
                        'label' => $badge['label'],
                        'color' => $badge['color'] ?? 'gray',
                    ];
            }
            $fallbackLabel = match (true) {
                is_string($item) => $item,
                $item instanceof UnitEnum => $item->name,
                $item instanceof Stringable => (string) $item,
                default => $id,
            };

            $normalized[] = [
                'id' => $id,
                'label' => $item instanceof HasLabel ? $item->getLabel() ?? $fallbackLabel : $fallbackLabel,
                'icon' => $item instanceof HasIcon ? $item->getIcon() : null,
                'description' => $item instanceof HasDescription ? $item->getDescription() : null,
                'type' => $types[$id] ?? null,
                'required' => in_array($id, $required, true),
                'badges' => $itemBadges,
            ];
        }

        return $normalized;
    }
}
