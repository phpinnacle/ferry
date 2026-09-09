<?php

namespace PHPinnacle\Ferry\Resources\Syncs\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Ferry\Resources\Syncs\SyncResource;

class ListSyncs extends ListRecords
{
    protected static string $resource = SyncResource::class;

    public function getTitle(): string
    {
        return '';
    }
}
