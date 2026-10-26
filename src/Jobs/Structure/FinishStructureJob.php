<?php

namespace PHPinnacle\Ferry\Jobs\Structure;

use PHPinnacle\Ferry\Models\Structure;

class FinishStructureJob extends StructureJob
{
    public function handle(): void
    {
        new Structure()
            ->getConnection()
            ->transaction(function () {
                $this->lockedStructure()?->publish();
            });
    }
}
