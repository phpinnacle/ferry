<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
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

    public function get(?string $key): ?StaticDestination
    {
        return $key === null ? null : $this->items[$key] ?? null;
    }

    public function getOrFail(string $key): StaticDestination
    {
        return (
            $this->get($key) ?? throw new LogicException(__(
                'phpinnacle-ferry::resources.sync.errors.static_destination_missing',
                ['destination' => $key],
            ))
        );
    }

    /** @return array<string, StaticDestination> */
    public function all(): array
    {
        return $this->items;
    }
}
