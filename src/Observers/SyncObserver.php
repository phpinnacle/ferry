<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\SyncTableManager;

class SyncObserver
{
    public function __construct(
        private readonly SyncTableManager $tables,
        private readonly DestinationFactory $destinations,
        private readonly ConnectorManager $connectors,
    ) {}

    public function creating(Sync $sync): void
    {
        $destination = $this->destinations->resolve($sync);

        if ($sync->type === DestinationType::Dynamic) {
            $sync->destination = $destination->table;
        }
    }

    public function created(Sync $sync): void
    {
        if ($sync->type === DestinationType::Dynamic) {
            $this->tables->create($sync);
        }
    }

    public function deleted(Sync $sync): void
    {
        if ($sync->connector !== null) {
            $this->connectors->delete($sync);
        }

        if ($sync->type === DestinationType::Dynamic) {
            $this->tables->drop($sync);
        }
    }

    public function updating(Sync $sync): void
    {
        if ($sync->type !== DestinationType::Dynamic) {
            return;
        }

        if (!$sync->isDirty('schema')) {
            return;
        }

        $this->tables->update($sync);
    }

    public function saved(Sync $sync): void
    {
        if ($sync->isDirty(['status', 'is_paused', 'connector_id']) || $sync->connector === null) {
            return;
        }

        $sync->getConnection()->afterCommit(function () use ($sync) {
            $object = $sync->connection->publishedObject($sync->source);

            if (
                $object !== null
                && $sync->shouldResume($object, $this->destinations->fieldsFor($sync))
            ) {
                $this->connectors->activate($sync);
            }
        });
    }
}
