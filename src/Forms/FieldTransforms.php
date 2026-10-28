<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Schemas\Components\Component;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\InlineBuilder;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @phpstan-import-type TransformData from TransformSetting
 * @phpstan-import-type Definition from JsonSchema
 */
class FieldTransforms extends InlineBuilder
{
    protected ?StructureItem $object = null;

    protected TransformTarget $target = TransformTarget::Value;

    /** @var Definition|Closure(): Definition */
    protected array|Closure $inputSchema = [];

    public function target(TransformTarget $target): static
    {
        $this->target = $target;

        return $this;
    }

    /** @param Definition|Closure(): Definition $schema */
    public function inputSchema(array|Closure $schema): static
    {
        $this->inputSchema = $schema;

        return $this;
    }

    public function getInputSchema(?int $index = null): ?JsonSchema
    {
        return $this->resolveSettings(app(TransformRegistry::class), $index)['schema'];
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

    /** @return array<string, Transform> */
    public function getAvailableTransforms(TransformRegistry $transforms): array
    {
        $schema = $this->resolveSettings($transforms)['schema'];
        if ($schema === null) {
            return [];
        }

        return array_filter(
            $transforms->all(),
            fn (Transform $transform) => $transform->getTarget() === $this->target && $transform->accepts($schema),
        );
    }

    /** @return array<string, Block> */
    public function getAvailableBlocks(): array
    {
        $blocks = parent::getAvailableBlocks();
        if ($blocks === []) {
            return [];
        }

        return array_intersect_key(
            $blocks,
            $this->getAvailableTransforms(app(TransformRegistry::class)),
        );
    }

    public function object(?StructureItem $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function getObject(): ?StructureItem
    {
        return $this->object;
    }

    /** @return list<array{type: string, data: array<string, mixed>}> */
    public function getSettings(): array
    {
        if ($this->getItems() === []) {
            return [];
        }

        return array_map(
            static fn (TransformSetting $setting) => $setting->jsonSerialize(),
            $this->resolveSettings(app(TransformRegistry::class))['settings'],
        );
    }

    /** @return array{settings: list<TransformSetting>, schema: JsonSchema|null, invalid: TransformSetting|null} */
    private function resolveSettings(TransformRegistry $registry, ?int $beforeIndex = null): array
    {
        $settings = [];
        $invalid = null;
        $schema = JsonSchema::fromArray($this->evaluate($this->inputSchema));
        foreach (array_slice($this->getItems(), 0, $beforeIndex) as $index => $item) {
            $setting = new TransformSetting($item['type'], $this->getItemData($index, ['input' => $schema]));
            $settings[] = $setting;
            if ($schema !== null) {
                $schema = $registry->resultSchema($schema, [$setting], $this->target);
                if ($schema === null) {
                    $invalid = $setting;
                }
            }
        }

        return ['settings' => $settings, 'schema' => $schema, 'invalid' => $invalid];
    }

    /** @param list<TransformData> $settings */
    public function hasCompatibleTypes(array $settings, TransformRegistry $transforms): bool
    {
        return $transforms->resultSchema(
            JsonSchema::fromArray($this->evaluate($this->inputSchema)),
            array_map(TransformSetting::fromArray(...), $settings),
            $this->target,
        ) !== null;
    }

    public function getEditorActionName(): string
    {
        return 'fieldTransforms';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->addActionLabel(__('phpinnacle-ferry::resources.replica.fields.add_transform'));
        $this->placeholder(__('phpinnacle-ferry::resources.replica.fields.transform'));
        $this->blocks(fn (TransformRegistry $registry) => array_map(
            fn (Transform $transform) => $transform->block(),
            array_values($registry->all()),
        ));
        $this->reorderableUsing(self::canReorderItems(...));
        $this->rules(fn (self $component) => [$component->validateSettings(...)]);
    }

    /** @param list<TransformData> $items */
    private static function canReorderItems(self $component, array $items): bool
    {
        return $component->hasCompatibleTypes($items, app(TransformRegistry::class));
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(string $attribute, mixed $value, Closure $fail): void
    {
        $transforms = app(TransformRegistry::class);
        $invalid = $this->resolveSettings($transforms)['invalid'];
        if ($invalid !== null) {
            $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                'transform' => $transforms->getOrFail($invalid->type)->block()->getLabel(),
            ]);
        }
    }
}
