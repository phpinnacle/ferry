<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\Actions\FieldTransformsAction;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Transformation;

/**
 * @phpstan-type Transform array{key: string, params?: array<string, string>}
 * @phpstan-import-type Definition from JsonSchema
 */
class FieldTransforms extends Field
{
    protected string $view = 'phpinnacle-ferry::forms.field-transforms';

    protected ?ConnectionMetadata $object = null;

    /** @var Definition|Closure(): Definition */
    protected array|Closure $inputSchema = [];

    /** @param Definition|Closure(): Definition $schema */
    public function inputSchema(array|Closure $schema): static
    {
        $this->inputSchema = $schema;

        return $this;
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
            $transforms->forScope(TransformScope::Field),
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->default([]);
        $this->rules(fn (self $component) => [
            $component->validateSettings(...),
        ]);
        $this->registerActions([
            fn (self $component, TransformRegistry $transforms) => FieldTransformsAction::make($component, $transforms),
        ]);
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(string $attribute, mixed $value, Closure $fail): void
    {
        $transforms = app(TransformRegistry::class);
        $validator = Validator::make(['transforms' => $value], [
            'transforms' => ['array', 'list'],
            'transforms.*' => ['array:key,params'],
            'transforms.*.key' => [
                'required',
                'string',
                Rule::in(array_keys($transforms->forScope(TransformScope::Field))),
            ],
            'transforms.*.params' => ['sometimes', 'array'],
            'transforms.*.params.*' => ['string'],
        ]);
        if ($validator->fails()) {
            $fail($validator->errors()->first())->translate();

            return;
        }
        /** @var list<Transform> $settings */
        $settings = $validator->validated()['transforms'];
        $schema = JsonSchema::fromArray($this->evaluate($this->inputSchema));
        foreach ($settings as $index => $setting) {
            $transform = $transforms->getOrFail($setting['key']);
            $params = $setting['params'] ?? [];
            $this
                ->makeChildSchema((string) $index)
                ->statePath($index . '.params')
                ->components($transform->getSchema($this->object))
                ->validate();
            $schema = $transform->accepts($schema) ? $transform->getOutputSchema($schema, $params) : null;
            if ($schema === null) {
                $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                    'transform' => $transform->getLabel(),
                ]);

                return;
            }
        }
    }
}
