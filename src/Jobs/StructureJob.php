<?php

namespace PHPinnacle\Ferry\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use Throwable;

abstract class StructureJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $runId,
    ) {
        $this->timeout = Config::integer('phpinnacle-ferry.structure.timeout');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> */
        return Config::array('phpinnacle-ferry.structure.backoff');
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        new Connection()
            ->getConnection()
            ->transaction(function () use ($exception) {
                $this->lockedConnection()?->failStructure(
                    $exception !== null ? ConnectionErrorFormatter::format($exception) : null,
                );
            });
    }

    protected function connection(): ?Connection
    {
        return Connection::structureRun($this->connectionId, $this->runId)->first();
    }

    protected function lockedConnection(): ?Connection
    {
        return Connection::structureRun($this->connectionId, $this->runId)->lockForUpdate()->first();
    }
}
