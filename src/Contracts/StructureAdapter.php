<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;

interface StructureAdapter extends HasLabel
{
    public function getKey(): string;

    public function getLabel(): string;

    public function prepare(Upstream $upstream): StructurePlan;

    /** @return list<StructureItem> Unsaved root objects with their children loaded. */
    public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array;
}
