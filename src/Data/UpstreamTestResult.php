<?php

namespace PHPinnacle\Ferry\Data;

final readonly class UpstreamTestResult
{
    private function __construct(
        public bool $success,
        public string $message,
    ) {}

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }
}
