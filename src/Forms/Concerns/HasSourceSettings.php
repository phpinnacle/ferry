<?php

namespace PHPinnacle\Ferry\Forms\Concerns;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use PHPinnacle\Ferry\Forms\JsonSchema;

/**
 * @phpstan-require-extends Field
 * @phpstan-import-type MappingItem from JsonSchema
 */
trait HasSourceSettings
{
    /** @var array<Component>|Closure(): array<Component> */
    protected array|Closure $sourceSettingsSchema = [];

    /** @var array<string, string>|null */
    private ?array $cachedSourceSettingsKeys = null;

    /** @return list<MappingItem> */
    abstract public function getSources(): array;

    abstract public function isSourceUsed(string $source): bool;

    /** @param array<Component>|Closure $schema */
    public function sourceSettings(array|Closure $schema): static
    {
        $this->sourceSettingsSchema = $schema;

        return $this;
    }

    public function hasSourceSettings(): bool
    {
        return $this->sourceSettingsSchema !== [];
    }

    /** @return array<string, string> */
    public function getSourceStateKeys(): array
    {
        $keys = [];
        foreach ($this->getSources() as $source) {
            $keys[$source['id']] = 'source_' . bin2hex($source['id']);
        }

        return $keys;
    }

    /** @return array<string, array<string, mixed>> */
    public function getSourceSettings(): array
    {
        /** @var array{sources?: array<string, array<string, mixed>>} $state */
        $state = $this->getRawState() ?? [];

        return $this->decodeSourceSettings($state['sources'] ?? []);
    }

    /** @return array<string, Schema> */
    protected function makeSourceSettingsSchemas(): array
    {
        $schemas = [];
        $this->cachedSourceSettingsKeys = $this->hasSourceSettings() ? $this->getSourceStateKeys() : [];
        foreach ($this->cachedSourceSettingsKeys as $source => $key) {
            $schemas['settings_' . $key] = $this
                ->makeChildSchema('settings_' . $key)
                ->statePath('sources.' . $key)
                ->components([
                    Group::make($this->evaluate($this->sourceSettingsSchema, ['source' => (string) $source]))
                        ->dehydrated(fn () => $this->isSourceUsed((string) $source))
                        ->validatedWhenNotDehydrated(false),
                ])
                ->getClone();
        }

        return $schemas;
    }

    protected function areSourceSettingsSchemasFresh(): bool
    {
        return $this->cachedSourceSettingsKeys === ($this->hasSourceSettings() ? $this->getSourceStateKeys() : []);
    }

    /** @param array<string, array<string, mixed>> $provided */
    protected function fillSourceSettingsDefaults(array $provided): void
    {
        if (!$this->hasSourceSettings()) {
            return;
        }
        foreach ($this->getSourceStateKeys() as $key) {
            if (!array_key_exists($key, $provided)) {
                $this->getChildSchemas()['settings_' . $key]->fill(
                    shouldCallHydrationHooks: false,
                    shouldApplyStateCasts: false,
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $settings
     * @return array<string, array<string, mixed>>
     */
    protected function encodeSourceSettings(array $settings): array
    {
        $keys = $this->getSourceStateKeys();

        return array_combine(
            array_map(fn (int|string $key) => $keys[$key] ?? $key, array_keys($settings)),
            array_values($settings),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $settings
     * @return array<string, array<string, mixed>>
     */
    protected function decodeSourceSettings(array $settings): array
    {
        $keys = array_flip($this->getSourceStateKeys());

        return array_combine(
            array_map(fn (int|string $key) => $keys[$key] ?? $key, array_keys($settings)),
            array_values($settings),
        );
    }
}
