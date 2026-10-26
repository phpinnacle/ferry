<?php

namespace PHPinnacle\Ferry\Data;

final readonly class StructurePlan
{
    /** @param array<string, mixed> $context Adapter-owned state serialized between chunks. */
    public function __construct(
        public int $estimated,
        public array $context = [],
    ) {}
}
