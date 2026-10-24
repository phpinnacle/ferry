<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Casts\FieldDecoder;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Rosetta\Contracts\Field;
use PHPinnacle\Rosetta\Fields\StringField;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 * @phpstan-import-type Definition from JsonSchema
 * @phpstan-type MappingData array{kind: 'source', source: string, target: string|null, field: FieldData, result?: Definition|null, transforms?: list<array{type: string, data: array<string, string>}>}|array{kind: 'static', target: string, static: string|int|float|bool, result?: Definition|null, transforms?: list<array{type: string, data: array<string, string>}>}
 */
final readonly class FieldMapping implements JsonSerializable
{
    /** @param list<TransformSetting> $transforms */
    public function __construct(
        public ?string $source,
        public ?string $target,
        public ?Field $field,
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
                field: FieldDecoder::decode($data['field']),
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
        /** @var Field $field */
        $field = $this->field;

        /** @var FieldData $data */
        $data = $field->jsonSerialize();

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

    public function sourceType(): ?ColumnType
    {
        if ($this->kind === FieldKind::Static) {
            /** @var string|int|float|bool $value */
            $value = $this->static;

            return ColumnType::fromValue($value);
        }

        return $this->field !== null ? ColumnType::fromField($this->field) : null;
    }

    public function type(): ?ColumnType
    {
        if ($this->result !== null) {
            return ColumnType::fromJsonSchema($this->result);
        }

        return $this->transforms === [] ? $this->sourceType() : null;
    }

    public function stringLength(): ?int
    {
        $length = match (true) {
            $this->result !== null => $this->result->maxLength(),
            $this->transforms === [] && $this->field instanceof StringField => $this->field->length,
            default => null,
        };

        return $length > 0 ? $length : null;
    }
}
