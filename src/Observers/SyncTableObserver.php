<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncTableManager;

class SyncTableObserver
{
    public function __construct(
        private readonly SyncTableManager $tables,
    ) {}

    public function created(Sync $sync): void
    {
        if ($sync->destinationType() === DestinationType::Dynamic) {
            $this->tables->create($sync);
        }
    }

    public function deleted(Sync $sync): void
    {
        if ($sync->destinationType() === DestinationType::Dynamic) {
            $this->tables->drop($sync);
        }
    }

    public function updating(Sync $sync): void
    {
        if ($sync->destinationType() !== DestinationType::Dynamic) {
            return;
        }

        if (!$sync->isDirty('schema')) {
            return;
        }

        /** @var list<FieldMapping> $previous */
        $previous = $sync->getOriginal('schema') ?? [];

        $this->tables->update($sync, $previous, $sync->schema);
    }
}
