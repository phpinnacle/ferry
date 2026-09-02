<?php

namespace PHPinnacle\Ferry\Resources\Connections\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Ferry\Resources\Connections\ConnectionResource;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    public function getTitle(): string
    {
        return '';
    }
}
