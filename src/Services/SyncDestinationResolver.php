<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Support\Facades\Config;
use LogicException;
use PHPinnacle\Ferry\Contracts\StaticDestination;

class SyncDestinationResolver
{
    public function __construct(
        private readonly StaticDestinationRegistry $destinations,
    ) {}

    public function destination(?string $staticDestination): ?StaticDestination
    {
        $destination = $this->find($staticDestination);

        if ($staticDestination !== null && $destination === null) {
            throw new LogicException(__('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
                'destination' => $staticDestination,
            ]));
        }

        return $destination;
    }

    public function find(?string $staticDestination): ?StaticDestination
    {
        return $staticDestination === null ? null : $this->destinations->get($staticDestination);
    }

    public function table(?string $staticDestination, string $code): string
    {
        return (
            $this->destination($staticDestination)?->table()
            ?? Config::string('phpinnacle-ferry.sync.table_prefix') . $code
        );
    }
}
