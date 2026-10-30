<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Illuminate\Translation\PotentiallyTranslatedString;
use PHPinnacle\Common\Forms\TabbedBuilder;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Transform;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Services\TransformRegistry;

/**
 * @phpstan-import-type TransformData from TransformSetting
 */
class RecordTransforms extends TabbedBuilder
{
    protected JsonSchema $inputSchema;

    public function inputSchema(JsonSchema $schema): static
    {
        $this->inputSchema = $schema;

        return $this;
    }

    public function getInputSchema(?string $before = null): JsonSchema
    {
        $items = [];
        /** @var array<string, TransformData> $state */
        $state = $this->getRawState() ?? [];
        foreach ($state as $key => $item) {
            if ((string) $key === $before) {
                break;
            }

            $items[] = $item;
        }

        return app(TransformRegistry::class)->previewRecordSchema(
            $this->inputSchema,
            array_map(TransformSetting::fromArray(...), $items),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->addActionLabel(__('phpinnacle-ferry::transforms.add'));
        $this->blocks(fn (TransformRegistry $registry) => array_values(array_map(
            fn (Transform $transform) => $transform->block(),
            array_filter(
                $registry->all(),
                fn (Transform $transform) => $transform->getScope() === TransformScope::Record,
            ),
        )));
        $this->live();
        $this->rules(fn (TransformRegistry $transforms) => [
            fn (string $attribute, mixed $value, Closure $fail) => $this->validateSettings(
                $transforms,
                $attribute,
                $value,
                $fail,
            ),
        ]);
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(
        TransformRegistry $transforms,
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if ($value !== null && !is_array($value)) {
            $fail('phpinnacle-ferry::validation.replica_transforms')->translate();

            return;
        }

        /** @var array<string, TransformData>|null $value */
        if (
            $transforms->resultSchema(
                $this->inputSchema,
                array_map(TransformSetting::fromArray(...), array_values($value ?? [])),
                null,
                TransformScope::Record,
            ) === null
        ) {
            $fail('phpinnacle-ferry::validation.replica_transforms')->translate();
        }
    }
}
