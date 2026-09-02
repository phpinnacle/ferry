<?php

namespace PHPinnacle\Ferry\Jobs;

use PHPinnacle\Ferry\Models\Connection;

class FinishStructureJob extends StructureJob
{
    public function handle(): void
    {
        new Connection()
            ->getConnection()
            ->transaction(function () {
                $this->lockedConnection()?->publishStructure();
            });
    }
}
