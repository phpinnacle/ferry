<?php

namespace PHPinnacle\Ferry\Data;

final readonly class MonitoringSample
{
    public function __construct(
        public string $name,
        public ?string $context,
        public ?float $value,
        public float $sampledAt,
    ) {}
}
