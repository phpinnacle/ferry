<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Models\Structure;

class StructureObserver
{
    public function saved(Structure $structure): void
    {
        if ($structure->isDirty('run_id') && $structure->run_id !== null) {
            PrepareStructureJob::dispatch($structure->upstream_id, $structure->run_id)->afterCommit();
        }
    }
}
