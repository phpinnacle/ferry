<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Schemas\Components\Component;
use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Data\CapturePlan;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;

interface Adapter extends HasLabel
{
    public function getKey(): string;

    public function getLabel(): string;

    /** @return list<Component> */
    public function form(): array;

    public function capture(Upstream $upstream): CapturePlan;

    public function prepare(Upstream $upstream): StructurePlan;

    /** @return list<StructureItem> Unsaved root objects with their children loaded. */
    public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array;
}
