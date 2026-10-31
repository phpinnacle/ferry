<?php

namespace PHPinnacle\Ferry\Destinations;

use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;

final readonly class DynamicDestination implements Destination
{
    public function __construct(
        private Replica $replica,
        private ReplicaSchemaResolver $schemas,
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
        $columns = $this->replica->columnMap();

        /** @var non-empty-list<string> */
        return array_map(
            fn (string $source) => $columns[$source],
            $this->schemas->primaryKeySources($this->replica),
        );
    }

    /** @return array<string, string> */
    public function getFixedValues(): array
    {
        return [];
    }
}
