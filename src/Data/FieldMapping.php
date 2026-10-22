<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Ferry\Casts\FieldDecoder;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Rosetta\Contracts\Field;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 *
 * @phpstan-type MappingData array{source: string|null, column: string, field: FieldData|null, result?: value-of<ColumnType>, transforms?: list<array{key: string, params: array<string, string>}>, constant?: array{type: value-of<ColumnType>, value: string}}
 */
final readonly class FieldMapping implements JsonSerializable
{
    /** @param list<TransformSetting> $transforms */
    public function __construct(
        public ?string $source,
        public string $column,
        public ?Field $field,
        public ?ColumnType $result = null,
        public array $transforms = [],
        public ?StaticValue $constant = null,
    ) {}

    /** @param MappingData $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            column: $data['column'],
            field: $data['field'] !== null ? FieldDecoder::decode($data['field']) : null,
            result: ($data['result'] ?? null) !== null ? ColumnType::from($data['result']) : null,
            transforms: array_map(TransformSetting::fromArray(...), $data['transforms'] ?? []),
            constant: ($data['constant'] ?? null) !== null ? StaticValue::fromArray($data['constant']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'column' => $this->column,
            'field' => $this->field?->jsonSerialize(),
            ...($this->result !== null ? ['result' => $this->result->value] : []),
            ...($this->transforms !== [] ? ['transforms' => $this->transforms] : []),
            ...($this->constant !== null ? ['constant' => $this->constant] : []),
        ];
    }

    public function identity(): string
    {
        return $this->source ?? 'constant:' . $this->column;
    }

    public function sourceType(): ?ColumnType
    {
        return $this->field !== null ? ColumnType::fromField($this->field) : null;
    }

    public function type(): ?ColumnType
    {
        return $this->constant->type ?? $this->result ?? $this->sourceType();
    }
}
