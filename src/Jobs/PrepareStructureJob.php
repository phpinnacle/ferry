<?php

namespace PHPinnacle\Ferry\Jobs;

use PHPinnacle\Ferry\Services\StructureAdapterRegistry;

class PrepareStructureJob extends StructureJob
{
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

        if ($structure->readPlan() === null) {
            $plan = $adapters->getOrFail($key)->prepare($structure->upstream);

            if (!$structure->plan($plan)) {
                return;
            }
        }

        if ($structure->estimated === 0) {
            FinishStructureJob::dispatch($this->upstreamId, $this->runId);

            return;
        }

        FetchStructureChunkJob::dispatch(
            $this->upstreamId,
            $this->runId,
            $structure->processed,
        );
    }
}
