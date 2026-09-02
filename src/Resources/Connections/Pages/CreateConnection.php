<?php

namespace PHPinnacle\Ferry\Resources\Connections\Pages;

use Filament\Resources\Pages\CreateRecord;
use PHPinnacle\Ferry\Resources\Connections\ConnectionResource;

class CreateConnection extends CreateRecord
{
    protected static string $resource = ConnectionResource::class;

    public function getTitle(): string
    {
        return __('phpinnacle-ferry::resources.connection.pages.create');
    }
}
