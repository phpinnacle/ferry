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
                    $exception === null ? null : ConnectionErrorFormatter::format($exception),
                );
            });
    }

    protected function connection(): ?Connection
    {
        return $this->current(Connection::query()->find($this->connectionId));
    }

    protected function lockedConnection(): ?Connection
    {
        return $this->current(Connection::query()->lockForUpdate()->find($this->connectionId));
    }

    private function current(?Connection $connection): ?Connection
    {
        if ($connection === null) {
            return null;
        }

        if ($connection->run_id !== $this->runId) {
            return null;
        }

        return $connection;
    }
}
