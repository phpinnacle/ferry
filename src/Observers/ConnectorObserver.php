<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Jobs\SynchronizeConnectorsJob;
use PHPinnacle\Ferry\Models\Connector;

class ConnectorObserver
{
    public function created(Connector $connector): void
    {
        SynchronizeConnectorsJob::dispatch($connector->upstream_id);
    }

    public function updated(Connector $connector): void
    {
        if ($connector->isDirty('config')) {
            SynchronizeConnectorsJob::dispatch($connector->upstream_id);
        }
    }
}
