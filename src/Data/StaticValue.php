<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;
use PHPinnacle\Ferry\Enums\ColumnType;

final readonly class StaticValue implements JsonSerializable
{
    public function __construct(
        public ColumnType $type,
        public string $value,
    ) {}

    /** @param array{type: value-of<ColumnType>, value: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(ColumnType::from($data['type']), $data['value']);
    }

    /** @return array{type: value-of<ColumnType>, value: string} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }
}
