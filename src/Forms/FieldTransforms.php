<?php

namespace PHPinnacle\Ferry\Forms;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\TransformScope;
use PHPinnacle\Ferry\Forms\Actions\FieldTransformsAction;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\TransformFactory;
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

    private function isScalarInput(): bool
    {
        $types = array_values(array_diff(
            JsonSchema::fromArray($this->evaluate($this->inputSchema))->types(),
            ['null'],
        ));

        return (
            count($types) <= 1
            && array_diff($types, [
                'string',
                'integer',
                'number',
                'boolean',
            ]) === []
        );
    }

    private function inputType(): ?ColumnType
    {
        $schema = JsonSchema::fromArray($this->evaluate($this->inputSchema));
        $types = array_values(array_diff($schema->types(), ['null']));
        foreach (ColumnType::cases() as $type) {
            $definition = $type->jsonSchema();
            if ($types === [$definition['type']] && $schema->annotation('format') === ($definition['format'] ?? null)) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string, Transformation> */
    public function getAvailableTransforms(TransformFactory $transforms): array
    {
        if (!$this->isScalarInput()) {
            return [];
        }

        $type = $transforms->resultType($this->inputType(), $this->getSettings());
        if ($type === null && $this->getSettings() !== []) {
            return [];
        }

        return array_filter(
            $transforms->forScope(TransformScope::Field),
            fn (Transformation $transform) => $transform->accepts($type),
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
        $this->rules(fn () => [
            $this->validateSettings(...),
        ]);
        $this->registerActions([
            fn (self $component, TransformFactory $transforms) => FieldTransformsAction::make($component, $transforms),
        ]);
    }

    /** @param Closure(string): PotentiallyTranslatedString $fail */
    private function validateSettings(string $attribute, mixed $value, Closure $fail): void
    {
        $transforms = app(TransformFactory::class);
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
        $type = $this->inputType();
        foreach ($settings as $index => $setting) {
            $transform = $transforms->getOrFail($setting['key']);
            $params = $setting['params'] ?? [];
            $this
                ->makeChildSchema((string) $index)
                ->statePath($index . '.params')
                ->components($transform->getSchema($this->object))
                ->validate();
            $type = $this->isScalarInput() && $transform->accepts($type)
                ? $transform->getOutputType($type, $params)
                : null;
            if ($type === null) {
                $fail('phpinnacle-ferry::validation.transform_input_type')->translate([
                    'transform' => $transform->getLabel(),
                ]);

                return;
            }
        }
    }
}
