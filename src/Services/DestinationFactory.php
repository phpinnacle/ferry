<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Destinations\DynamicDestination;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Jason\Schema;

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
                'phpinnacle-ferry::resources.replica.errors.static_destination_missing',
                ['destination' => $key],
            ))
        );
    }

    public function resolve(Replica $replica): Destination
    {
        return match ($replica->type) {
            DestinationType::Static => $this->getOrFail($replica->destination),
            DestinationType::Dynamic => new DynamicDestination($replica),
        };
    }

    public function schemaFor(Replica $replica): ?Schema
    {
        return $replica->type === DestinationType::Static ? $this->get($replica->destination)?->getSchema() : null;
    }

    /** @return array<string, StaticDestination> */
    public function all(): array
    {
        return $this->items;
    }
}
