<?php

namespace PHPinnacle\Ferry\Jobs;

use PHPinnacle\Ferry\Services\StructureImporter;

class PrepareStructureJob extends StructureJob
{
    public function handle(StructureImporter $importer): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        if ($connection->storage_map === null || $connection->type_map === null) {
            $plan = $importer->prepare($connection);

            if (!$connection->planStructure($plan['storageMap'], $plan['typeMap'], $plan['total'])) {
                return;
            }
        }

        if ($connection->total === 0) {
            FinishStructureJob::dispatch($this->connectionId, $this->runId);

            return;
        }

        FetchStructureChunkJob::dispatch(
            $this->connectionId,
            $this->runId,
            $connection->processed,
        );
    }
}
