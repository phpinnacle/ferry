<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Ferry\Casts\FieldDecoder;
use PHPinnacle\Ferry\Enums\ColumnType;
use PHPinnacle\Rosetta\Contracts\Field;

/**
 * @phpstan-import-type FieldData from FieldDecoder
 */
final readonly class FieldMapping implements JsonSerializable
{
    public function __construct(
        public string $source,
        public string $column,
        public Field $field,
    ) {}

    /** @param array{source: string, column: string, field: FieldData} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'],
            column: $data['column'],
            field: FieldDecoder::decode($data['field']),
        );
    }

    /** @return array{source: string, column: string, field: mixed} */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'column' => $this->column,
            'field' => $this->field->jsonSerialize(),
        ];
    }

    public function type(): ?ColumnType
    {
        return ColumnType::fromField($this->field);
    }
}
