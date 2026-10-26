<?php

namespace PHPinnacle\Ferry\Services;

use LogicException;
use PHPinnacle\Ferry\Contracts\Adapter;

class StructureAdapterRegistry
{
    /** @var array<string, Adapter> */
    private array $items = [];

    public function register(Adapter ...$adapters): void
    {
        foreach ($adapters as $adapter) {
            $this->items[$adapter->getKey()] = $adapter;
        }
    }

    public function getOrFail(string $key): Adapter
    {
        return $this->items[$key] ?? throw new LogicException(sprintf('Unknown structure adapter [%s].', $key));
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(static fn (Adapter $adapter) => $adapter->getLabel(), $this->items);
    }
}
