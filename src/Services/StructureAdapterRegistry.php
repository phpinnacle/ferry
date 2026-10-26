<?php

namespace PHPinnacle\Ferry\Services;

use LogicException;
use PHPinnacle\Ferry\Contracts\StructureAdapter;

class StructureAdapterRegistry
{
    /** @var array<string, StructureAdapter> */
    private array $items = [];

    public function register(StructureAdapter ...$adapters): void
    {
        foreach ($adapters as $adapter) {
            $this->items[$adapter->getKey()] = $adapter;
        }
    }

    public function getOrFail(string $key): StructureAdapter
    {
        return $this->items[$key] ?? throw new LogicException(sprintf('Unknown structure adapter [%s].', $key));
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(static fn (StructureAdapter $adapter) => $adapter->getLabel(), $this->items);
    }
}
