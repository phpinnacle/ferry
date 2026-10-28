<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;

/** @phpstan-type TransformData array{type: string, data?: array<string, mixed>} */
final readonly class TransformSetting implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $type,
        public array $data = [],
    ) {}

    /** @param TransformData $data */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['data'] ?? []);
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
