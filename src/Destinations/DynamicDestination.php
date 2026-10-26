<?php

namespace PHPinnacle\Ferry\Destinations;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\Replica;

final readonly class DynamicDestination implements Destination
{
    public function __construct(
        private Replica $replica,
    ) {}

    public function getLabel(): string
    {
        return $this->replica->destination;
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
        return $this->replica->destination;
    }

    public function getConnectionName(): ?string
    {
        return null;
    }

    /** @return non-empty-list<string> */
    public function getPrimaryKey(): array
    {
        /** @var non-empty-list<string> */
        return array_values($this->replica->primaryKeyMap($this->replica->sourceObject()));
    }

    /** @return array<string, string> */
    public function getFixedValues(): array
    {
        return [];
    }
}
