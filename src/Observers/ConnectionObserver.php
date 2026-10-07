<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\DestinationFactory;

class ConnectionObserver
{
    public function __construct(
        private readonly DestinationFactory $destinations,
        private readonly ConnectorManager $connectors,
    ) {}

    public function deleting(Connection $connection): void
    {
        $connection->load('syncs.connector');
    }

    public function deleted(Connection $connection): void
    {
        if ($connection->connector !== null) {
            $this->connectors->deleteSource($connection);
        }

        foreach ($connection->syncs as $sync) {
            $sync->connector?->delete();
        }
    }

    public function updated(Connection $connection): void
    {
        if ($connection->structurePublished()) {
            $this->reviewSyncs($connection);
        }

        if (!$connection->credentialsChanged()) {
            return;
        }

        $connection->handleCredentialsChanged();

        if ($connection->connector !== null) {
            $this->connectors->refreshSource($connection);
        }
    }

    private function reviewSyncs(Connection $connection): void
    {
        $objects = $connection->publishedMetadata()->get()->keyBy('external_id');

        foreach ($connection->syncs()->with('connector')->get() as $sync) {
            if ($sync->status === SyncStatus::Pause) {
                continue;
            }

            $object = $objects->get($sync->source);

            if (
                !$object instanceof ConnectionMetadata
                || !$sync->hasValidSchema(
                    $object,
                    $this->destinations->get($sync->static_destination)?->fields,
                )
            ) {
                if ($sync->connector !== null) {
                    $this->connectors->pause($sync, manually: false);
                } else {
                    $sync->pause(manually: false);
                }
            }
        }
    }
}
