<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use PHPinnacle\Ferry\Contracts\StaticDestination;

#[Singleton]
class StaticDestinationRegistry
{
    /** @var array<string, StaticDestination> */
    private array $items = [];

    public function register(StaticDestination ...$destinations): void
    {
        foreach ($destinations as $destination) {
            $this->items[$destination->key()] = $destination;
        }
    }

    public function get(string $key): ?StaticDestination
    {
        return $this->items[$key] ?? null;
    }

    /** @return array<string, StaticDestination> */
    public function all(): array
    {
        return $this->items;
    }
}
