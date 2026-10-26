<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\ConnectorManager;

class UpstreamObserver
{
    public function __construct(
        private readonly ConnectorManager $connectors,
    ) {}

    public function created(Upstream $upstream): void
    {
        $upstream->setRelation('structure', $upstream->structure()->create());
    }

    public function deleting(Upstream $upstream): void
    {
        foreach ($upstream->replicas()->get() as $replica) {
            $replica->delete();
        }
    }

    public function deleted(Upstream $upstream): void
    {
        $this->connectors->removedUpstream($upstream);
    }

    public function updated(Upstream $upstream): void
    {
        $credentialsChanged = $upstream->credentialsChanged();
        if ($credentialsChanged || $upstream->wasChanged('adapter')) {
            $upstream->structure->restart();
        }

        if ($credentialsChanged) {
            $this->connectors->refresh($upstream);
        }
    }
}
