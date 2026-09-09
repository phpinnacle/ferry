<?php

namespace PHPinnacle\Ferry\Data;

use PHPinnacle\Ferry\Enums\ColumnType;

final readonly class DestinationField
{
    public function __construct(
        public string $id,
        public string $label,
        public ColumnType $type,
        public bool $required,
    ) {}
}
