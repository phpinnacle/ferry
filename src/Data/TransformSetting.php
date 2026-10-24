<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;

final readonly class TransformSetting implements JsonSerializable
{
    /** @param array<string, string> $data */
    public function __construct(
        public string $type,
        public array $data = [],
    ) {}

    /** @param array{type: string, data?: array<string, string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['data'] ?? []);
    }

    /** @return array{type: string, data: array<string, string>} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
