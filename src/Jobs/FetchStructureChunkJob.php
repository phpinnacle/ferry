<?php

namespace PHPinnacle\Ferry\Jobs;

use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Services\StructureImporter;

class FetchStructureChunkJob extends StructureJob
{
    public function __construct(
        string $connectionId,
        string $runId,
        public readonly int $offset,
    ) {
        parent::__construct($connectionId, $runId);
    }

    public function handle(StructureImporter $importer): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $storageMap = $connection->storage_map;
        $typeMap = $connection->type_map;

        if ($storageMap === null || $typeMap === null) {
            return;
        }

        $count = $importer->importChunk(
            $connection,
            $storageMap,
            $typeMap,
            $this->offset,
            Config::integer('phpinnacle-ferry.structure.chunk_size'),
        );

        $processed = $this->offset + $count;

        if (!$connection->progressStructureTo($processed)) {
            return;
        }

        if ($count === 0 || $processed >= $connection->total) {
            FinishStructureJob::dispatch($this->connectionId, $this->runId);

            return;
        }

        self::dispatch($this->connectionId, $this->runId, $processed);
    }
}
