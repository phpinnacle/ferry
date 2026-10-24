<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\InlineBuilder;
use PHPinnacle\Common\Forms\InlineBuilder\Block;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;

/**
 * @phpstan-type Transform array{type: string, data?: array<string, string>}
 * @phpstan-import-type BuilderItem from InlineBuilder
 * @phpstan-import-type Definition from JsonSchema
 */
class FieldTransforms extends InlineBuilder
{
    protected ?ConnectionMetadata $object = null;

    /** @var Definition|Closure(): Definition */
    protected array|Closure $inputSchema = [];

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
        );
    }

    /** @return array<string, Transformation> */
    public function getAvailableTransforms(TransformRegistry $transforms): array
    {
        $schema = $transforms->resultSchema(
            JsonSchema::fromArray($this->evaluate($this->inputSchema)),
            $this->getSettings(),
        );
        if ($schema === null) {
            return [];
        }

        return array_filter(
            $transforms->all(),
            fn (Transformation $transform) => $transform->accepts($schema),
        );
    }

    public function object(?ConnectionMetadata $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function getObject(): ?ConnectionMetadata
    {
        return $this->object;
    }

    /** @return list<Transform> */
    public function getSettings(): array
    {
        /** @var list<Transform> */
        return $this->getRawState() ?? [];
    }

    /** @param list<Transform> $settings */
    public function hasCompatibleTypes(array $settings, TransformRegistry $transforms): bool
    {
        return (
            $transforms->resultSchema(
                JsonSchema::fromArray($this->evaluate($this->inputSchema)),
                $settings,
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
        $this->addActionLabel(__('phpinnacle-ferry::resources.sync.fields.add_transform'));
        $this->placeholder(__('phpinnacle-ferry::resources.sync.fields.transform'));
        $this->blocks(function (self $component) {
            $registry = app(TransformRegistry::class);

            return array_map(function (Transformation $transform) use ($component, $registry) {
                $block = Block::make($transform->key())
                    ->label($transform->getLabel())
                    ->description($transform->getDescription())
                    ->icon($transform->getIcon())
                    ->color($transform->getColor())
                    ->addable(fn () => array_key_exists(
                        $transform->key(),
                        $component->getAvailableTransforms($registry),
                    ));

                if ($transform->hasSchema()) {
                    $block->schema(fn (?int $index) => $transform->getSchema(
                        $component->getObject(),
                        $component->getInputSchema($index),
                    ));
                }

                return $block;
            }, array_values($registry->all()));
        });
        $this->reorderableUsing(self::canReorderItems(...));
        $this->rules(fn (self $component) => [$component->validateSettings(...)]);
    }

    /** @param list<Transform> $items */
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
        /** @var list<Transform> $settings */
        $transforms = app(TransformRegistry::class);
        $schema = JsonSchema::fromArray($this->evaluate($this->inputSchema));
        foreach ($settings as $setting) {
            $transform = $transforms->getOrFail($setting['type']);
            $schema = $transform->accepts($schema)
                ? $transform->getOutputSchema($schema, $setting['data'] ?? [])
                : null;
            if ($schema === null) {
                $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                    'transform' => $transform->getLabel(),
                ]);

                return;
            }
        }
    }
}
