<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;

class SyncConnectorObserver
{
    public function deleted(Sync $sync): void
    {
        if ($sync->connector !== null) {
            app(ConnectorManager::class)->delete($sync);
        }
    }
}
