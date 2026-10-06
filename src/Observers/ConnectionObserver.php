<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\SyncReviewer;

class ConnectionObserver
{
    public function __construct(
        private readonly SyncReviewer $syncs,
    ) {}

    public function deleting(Connection $connection): void
    {
        $connection->load('connector');
    }

    public function deleted(Connection $connection): void
    {
        if ($connection->connector !== null) {
            app(ConnectorManager::class)->deleteSource($connection);
        }
    }

    public function updated(Connection $connection): void
    {
        if ($connection->structurePublished()) {
            $this->syncs->review($connection);
        }

        if (!$connection->credentialsChanged()) {
            return;
        }

        $connection->handleCredentialsChanged();

        if ($connection->connector !== null) {
            app(ConnectorManager::class)->refreshSource($connection);
        }
    }
}
