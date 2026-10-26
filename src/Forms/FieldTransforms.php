<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\InlineBuilder;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Enums\TransformTarget;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @phpstan-import-type TransformInput from TransformRegistry
 * @phpstan-import-type BuilderItem from InlineBuilder
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
        return app(TransformRegistry::class)->resultSchema(
            JsonSchema::fromArray($this->evaluate($this->inputSchema)),
            $index === null ? $this->getSettings() : array_slice($this->getSettings(), 0, $index),
            $this->target,
        );
    }

    /** @return array<string, Transform> */
    public function getAvailableTransforms(TransformRegistry $transforms): array
    {
        $schema = $transforms->resultSchema(
            JsonSchema::fromArray($this->evaluate($this->inputSchema)),
            $this->getSettings(),
            $this->target,
        );
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

    /** @return list<TransformInput> */
    public function getSettings(): array
    {
        /** @var list<TransformInput> */
        return $this->getRawState() ?? [];
    }

    /** @param list<TransformInput> $settings */
    public function hasCompatibleTypes(array $settings, TransformRegistry $transforms): bool
    {
        return (
            $transforms->resultSchema(
                JsonSchema::fromArray($this->evaluate($this->inputSchema)),
                $settings,
                $this->target,
            ) !== null
        );
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

    /** @param list<TransformInput> $items */
    private static function canReorderItems(self $component, array $items): bool
    {
        return $component->hasCompatibleTypes($items, app(TransformRegistry::class));
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var list<BuilderItem> $settings */
        $settings = $value;
        foreach (array_keys($settings) as $index) {
            $settings[$index]['data'] = $this->getItemData($index);
        }
        $validator = Validator::make(['transforms' => $settings], ['transforms.*.data.*' => ['string']]);
        if ($validator->fails()) {
            $fail($validator->errors()->first())->translate();

            return;
        }
        /** @var list<TransformInput> $settings */
        $transforms = app(TransformRegistry::class);
        $schema = JsonSchema::fromArray($this->evaluate($this->inputSchema));
        foreach ($settings as $setting) {
            $schema = $transforms->resultSchema($schema, [$setting], $this->target);
            if ($schema === null) {
                $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                    'transform' => $transforms->getOrFail($setting['type'])->block()->getLabel(),
                ]);

                return;
            }
        }
    }
}
