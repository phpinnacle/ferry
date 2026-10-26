<?php

namespace PHPinnacle\Ferry\Observers;

use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Structure;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Services\DestinationFactory;

class StructureObserver
{
    public function __construct(
        private readonly DestinationFactory $destinations,
    ) {}

    public function updated(Structure $structure): void
    {
        if (!$structure->wasPublished()) {
            return;
        }

        $upstream = $structure->upstream;
        $objects = $structure->publishedItems()->get()->keyBy('external_id');

        foreach ($upstream->replicas()->with('connector')->get() as $replica) {
            if ($replica->status === ReplicaStatus::Pause) {
                continue;
            }

            $object = $objects->get($replica->source);

            if (
                !$object instanceof StructureItem
                || !$replica->hasValidSchema($object, $this->destinations->schemaFor($replica))
            ) {
                $replica->pause();
            }
        }
    }
}
