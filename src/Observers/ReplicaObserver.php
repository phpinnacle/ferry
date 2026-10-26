<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Services\ReplicaTableManager;

class ReplicaObserver
{
    public function __construct(
        private readonly ReplicaTableManager $tables,
        private readonly ConnectorManager $connectors,
    ) {}

    public function created(Replica $replica): void
    {
        if ($replica->type === DestinationType::Dynamic && $replica->schema !== []) {
            $this->tables->create($replica);
        }
    }

    public function deleted(Replica $replica): void
    {
        $this->connectors->removed($replica);
    }

    public function updating(Replica $replica): void
    {
        if ($replica->type !== DestinationType::Dynamic) {
            return;
        }

        if (!$replica->isDirty('schema')) {
            return;
        }

        if ($replica->getOriginal('schema') === []) {
            $this->tables->create($replica);
        } else {
            $this->tables->update($replica);
        }
    }

    public function saved(Replica $replica): void
    {
        $this->connectors->save($replica);
    }
}
