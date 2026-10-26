<?php

namespace PHPinnacle\Ferry\Destinations;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\Replica;

final readonly class DynamicDestination implements Destination
{
    public function __construct(
        private string $table,
    ) {}

    public function getLabel(): string
    {
        return $this->table;
    }

    public function getIcon(): ?string
    {
        return null;
    }

    public function getColor(): ?string
    {
        return null;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getConnectionName(): ?string
    {
        return null;
    }

    public function getPrimaryKey(): string
    {
        return Replica::ID_COLUMN;
    }

    /** @return array<string, string> */
    public function getFixedValues(): array
    {
        return [];
    }
}
