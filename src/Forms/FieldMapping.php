<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Ferry\Forms\Concerns\HasMappingItems;

/** @phpstan-import-type MappingItem from HasMappingItems */
// @mago-expect lint:too-many-properties
class FieldMapping extends Field
{
    use HasMappingItems;

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

    /** @param list<string>|Closure $targets */
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
     * @param  array<array-key, string>|Closure  $source
     * @param  array<array-key, string>|Closure  $dest
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
     * @param  array<array-key, string|object>|Closure  $source
     * @param  array<array-key, string|object>|Closure  $dest
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
}
