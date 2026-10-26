<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Eidos\Forms\SchemaCompatibility;
use PHPinnacle\Ferry\Enums\FieldKind;

/**
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-type MappingData array{kind: 'source', source: string, target: string|null, field: Definition, result?: Definition|null, transforms?: list<array{type: string, data: array<string, string>}>}|array{kind: 'static', target: string, static: string|int|float|bool, result?: Definition|null, transforms?: list<array{type: string, data: array<string, string>}>}
 */
final readonly class FieldMapping implements JsonSerializable
{
    /** @param list<TransformSetting> $transforms */
    public function __construct(
        public ?string $source,
        public ?string $target,
        public ?JsonSchema $field,
        public ?JsonSchema $result = null,
        public array $transforms = [],
        public string|int|float|bool|null $static = null,
        public FieldKind $kind = FieldKind::Source,
    ) {}

    /** @param MappingData $data */
    public static function fromArray(array $data): self
    {
        return match ($data['kind']) {
            'source' => new self(
                source: $data['source'],
                target: $data['target'],
                field: JsonSchema::fromArray($data['field']),
                result: ($data['result'] ?? null) !== null ? JsonSchema::fromArray($data['result']) : null,
                transforms: array_map(TransformSetting::fromArray(...), $data['transforms'] ?? []),
            ),
            'static' => new self(
                null,
                $data['target'],
                null,
                result: ($data['result'] ?? null) !== null ? JsonSchema::fromArray($data['result']) : null,
                transforms: array_map(TransformSetting::fromArray(...), $data['transforms'] ?? []),
                static: $data['static'],
                kind: FieldKind::Static,
            ),
        };
    }

    /** @return MappingData */
    public function jsonSerialize(): array
    {
        if ($this->kind === FieldKind::Static) {
            /** @var string $target */
            $target = $this->target;
            /** @var string|int|float|bool $value */
            $value = $this->static;

            $row = ['kind' => 'static', 'target' => $target, 'static' => $value];
            if ($this->transforms !== []) {
                $row['result'] = $this->result?->toArray();
                $row['transforms'] = array_map(
                    static fn (TransformSetting $setting) => $setting->jsonSerialize(),
                    $this->transforms,
                );
            }

            return $row;
        }

        /** @var string $source */
        $source = $this->source;
        /** @var JsonSchema $field */
        $field = $this->field;

        $data = $field->toArray();

        return [
            'kind' => 'source',
            'source' => $source,
            'target' => $this->target,
            'field' => $data,
            'result' => $this->result?->toArray(),
            'transforms' => array_map(
                static fn (TransformSetting $setting) => $setting->jsonSerialize(),
                $this->transforms,
            ),
        ];
    }

    public function identity(): string
    {
        return $this->source ?? 'static:' . $this->target;
    }

    public function isUsed(): bool
    {
        return $this->target !== null || $this->transforms !== [];
    }

    public function sourceSchema(): ?JsonSchema
    {
        if ($this->kind !== FieldKind::Static) {
            return $this->field;
        }

        return JsonSchema::fromArray([
            'type' => match (true) {
                is_bool($this->static) => 'boolean',
                is_int($this->static) => 'integer',
                is_float($this->static) => 'number',
                default => 'string',
            },
            'enum' => [$this->static],
            ...(
                is_string($this->static)
                    ? [
                        'minLength' => mb_strlen($this->static),
                        'maxLength' => mb_strlen($this->static),
                    ] : []
            ),
        ]);
    }

    public function outputSchema(): ?JsonSchema
    {
        return $this->result ?? ($this->transforms === [] ? $this->sourceSchema() : null);
    }

    public function canMapTo(JsonSchema $target): bool
    {
        $compatibility = SchemaCompatibility::make();
        if ($this->kind === FieldKind::Static && $this->transforms === []) {
            // Native static forms validate formats on values; raw values have no declared format.
            $compatibility = $compatibility->formats(false);
        }

        return $this->outputSchema()?->compatible($target, $compatibility) ?? false;
    }

    public function stringLength(): ?int
    {
        $length = match (true) {
            $this->result !== null => $this->result->maxLength(),
            $this->transforms === [] => $this->field?->maxLength(),
            default => null,
        };

        return $length > 0 ? $length : null;
    }
}
