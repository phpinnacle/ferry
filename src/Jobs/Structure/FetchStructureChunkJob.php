<?php

namespace PHPinnacle\Ferry\Jobs\Structure;

use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

class FetchStructureChunkJob extends StructureJob
{
    public function __construct(
        string $upstreamId,
        string $runId,
        public readonly int $offset,
    ) {
        parent::__construct($upstreamId, $runId);
    }

    public function handle(StructureAdapterRegistry $adapters): void
    {
        $structure = $this->structure();

        if ($structure === null) {
            return;
        }

        $key = $structure->upstream->adapter;
        if ($key === null) {
            return;
        }

        $plan = $structure->readPlan();

        if ($plan === null) {
            return;
        }

        $objects = $adapters->getOrFail($key)->read(
            $structure->upstream,
            $plan,
            $this->offset,
            Config::integer('phpinnacle-ferry.structure.chunk_size'),
        );

        if (!$structure->importItems($objects, $this->offset)) {
            return;
        }

        $count = count($objects);

        $processed = $this->offset + $count;

        if (!$structure->progressTo($processed)) {
            return;
        }

        if ($count === 0 || $processed >= $structure->estimated) {
            FinishStructureJob::dispatch($this->upstreamId, $this->runId);

            return;
        }

        self::dispatch($this->upstreamId, $this->runId, $processed);
    }
}
