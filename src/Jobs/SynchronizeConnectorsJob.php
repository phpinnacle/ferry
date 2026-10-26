<?php

namespace PHPinnacle\Ferry\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Exceptions\ConnectorOperationFailed;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\ConnectorManager;

class SynchronizeConnectorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public readonly string $upstreamId,
    ) {
        $this->onQueue('ferry')->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    public function handle(ConnectorManager $connectors): void
    {
        try {
            $connectors->synchronize($this->upstreamId);
        } catch (ConnectorOperationFailed $exception) {
            Connector::query()
                ->find($exception->connectorId)
                ?->recordStatus(
                    ConnectorStatus::Failed,
                    $exception->getMessage(),
                );

            throw $exception;
        }
    }
}
