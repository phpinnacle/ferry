<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Schemas\Components\Component;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\InlineBuilder;
use PHPinnacle\Common\Forms\InlineBuilder\Block as InlineBlock;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Jason\Schema;

/** @phpstan-import-type TransformData from TransformSetting */
class FieldTransforms extends InlineBuilder
{
    /** @var Schema|Closure(): Schema */
    protected Schema|Closure $inputSchema;

    /** @param Schema|Closure(): Schema $schema */
    public function inputSchema(Schema|Closure $schema): static
    {
        $this->inputSchema = $schema;

        return $this;
    }

    public function getInputSchema(?int $index = null): ?Schema
    {
        return $this->resolveSettings(app(ReplicaSchemaResolver::class), $index)['schema'];
    }

    /**
     * @param array<string, mixed> $injections
     * @return array<Component>
     */
    public function getItemComponents(string $type, ?int $index, array $injections = []): array
    {
        if (!array_key_exists('input', $injections)) {
            $injections['input'] = $this->getInputSchema($index);
        }

        return parent::getItemComponents($type, $index, $injections);
    }

    /** @return array<string, InlineBlock> */
    public function getAvailableBlocks(): array
    {
        $blocks = parent::getAvailableBlocks();
        if ($blocks === []) {
            return [];
        }

        $schema = $this->getInputSchema();
        if ($schema === null) {
            return [];
        }

        $registry = app(TransformRegistry::class);

        return array_filter(
            $blocks,
            fn (InlineBlock $block) => $registry->get($block->getName())->accepts($schema),
        );
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    public function getSettings(): array
    {
        if ($this->getItems() === []) {
            return [];
        }

        return array_map(
            static fn (TransformSetting $setting) => $setting->jsonSerialize(),
            $this->resolveSettings(app(ReplicaSchemaResolver::class))['settings'],
        );
    }

    /** @return array{settings: list<TransformSetting>, schema: Schema|null, invalid: TransformSetting|null} */
    private function resolveSettings(ReplicaSchemaResolver $schemas, ?int $beforeIndex = null): array
    {
        $settings = [];
        $invalid = null;
        $schema = $this->evaluate($this->inputSchema);
        foreach (array_slice($this->getItems(), 0, $beforeIndex) as $index => $item) {
            $setting = new TransformSetting($item['type'], $this->getItemData($index, ['input' => $schema]));
            $settings[] = $setting;
            if ($schema !== null) {
                $schema = $schemas->resultSchema($schema, [$setting]);
                if ($schema === null) {
                    $invalid = $setting;
                }
            }
        }

        return ['settings' => $settings, 'schema' => $schema, 'invalid' => $invalid];
    }

    public function getEditorActionName(): string
    {
        return 'fieldTransforms';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->addActionLabel(__('phpinnacle-ferry::transforms.add'));
        $this->placeholder(__('phpinnacle-ferry::transforms.label'));
        $this->blocks(fn (TransformRegistry $registry) => array_values(array_map(
            fn (Transform $transform) => $transform->block(),
            array_filter(
                $registry->all(),
                fn (Transform $transform) => $transform->getScope() === TransformScope::Field,
            ),
        )));
        $this->reorderableUsing($this->canReorderItems(...));
        $this->rules(fn (self $component, TransformRegistry $transforms) => [
            fn (string $attribute, mixed $value, Closure $fail) => $component->validateSettings(
                $transforms,
                $attribute,
                $value,
                $fail,
            ),
        ]);
    }

    /** @param list<TransformData> $items */
    private function canReorderItems(array $items, ReplicaSchemaResolver $schemas): bool
    {
        return $schemas->resultSchema(
            $this->evaluate($this->inputSchema),
            array_map(TransformSetting::fromArray(...), $items),
        ) !== null;
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(
        TransformRegistry $transforms,
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        $invalid = $this->resolveSettings(app(ReplicaSchemaResolver::class))['invalid'];
        if ($invalid !== null) {
            $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                'transform' => $transforms->get($invalid->type)->block()->getLabel(),
            ]);
        }
    }
}
