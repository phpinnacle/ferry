<?php

namespace PHPinnacle\Ferry\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Models\Structure;
use PHPinnacle\Ferry\Models\Upstream;
use Throwable;

abstract class StructureJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        public readonly string $upstreamId,
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

        new Structure()
            ->getConnection()
            ->transaction(function () {
                $this->lockedStructure()?->fail();
            });
    }

    protected function structure(): ?Structure
    {
        return Structure::run($this->upstreamId, $this->runId)->first();
    }

    protected function lockedStructure(): ?Structure
    {
        Upstream::withTrashed()->whereKey($this->upstreamId)->lockForUpdate()->first();

        return Structure::run($this->upstreamId, $this->runId)->lockForUpdate()->first();
    }
}
