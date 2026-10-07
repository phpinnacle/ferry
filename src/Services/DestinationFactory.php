<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Destinations\DynamicDestination;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Models\Sync;

#[Singleton]
class DestinationFactory
{
    /** @var array<string, StaticDestination> */
    private array $items = [];

    public function register(StaticDestination ...$destinations): void
    {
        foreach ($destinations as $destination) {
            $this->items[$destination->key] = $destination;
        }
    }

    public function get(?string $key): ?StaticDestination
    {
        return $key !== null ? $this->items[$key] ?? null : null;
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

    public function resolve(Sync $sync): Destination
    {
        return $sync->static_destination !== null
            ? $this->getOrFail($sync->static_destination)
            : new DynamicDestination($sync);
    }

    /** @return array<string, StaticDestination> */
    public function all(): array
    {
        return $this->items;
    }
}
