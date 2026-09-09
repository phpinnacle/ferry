<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;

class SyncDestinationObserver
{
    public function __construct(
        private readonly SyncDestinationResolver $resolver,
    ) {}

    public function creating(Sync $sync): void
    {
        $sync->destination = $this->resolver->table($sync->static_destination, $sync->code);
    }
}
