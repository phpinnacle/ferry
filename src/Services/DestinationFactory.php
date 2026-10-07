<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Container\Attributes\Singleton;
use LogicException;
use PHPinnacle\Ferry\Contracts\Destination;
use PHPinnacle\Ferry\Data\DestinationField;
use PHPinnacle\Ferry\Destinations\DynamicDestination;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
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
        return match ($sync->type) {
            DestinationType::Static => $this->getOrFail($sync->destination),
            DestinationType::Dynamic => new DynamicDestination($sync),
        };
    }

    /** @return list<DestinationField>|null */
    public function fieldsFor(Sync $sync): ?array
    {
        return $sync->type === DestinationType::Static ? $this->get($sync->destination)?->fields : null;
    }

    /** @return array<string, StaticDestination> */
    public function all(): array
    {
        return $this->items;
    }
}
