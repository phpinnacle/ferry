<?php

namespace PHPinnacle\Ferry\Data;

use JsonSerializable;

final readonly class TransformSetting implements JsonSerializable
{
    /** @param array<string, string> $params */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {}

    /** @param array{key: string, params: array<string, string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['params']);
    }

    /** @return array{key: string, params: array<string, string>} */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }
}
