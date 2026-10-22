<?php

namespace PHPinnacle\Ferry\Forms;

final readonly class SchemaCompatibility
{
    private function __construct(
        public bool $checkFormats = true,
        public bool $checkStringLengths = true,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function formats(bool $condition = true): self
    {
        return new self($condition, $this->checkStringLengths);
    }

    public function stringLengths(bool $condition = true): self
    {
        return new self($this->checkFormats, $condition);
    }
}
