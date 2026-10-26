<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;

class ListUpstreams extends ListRecords
{
    protected static string $resource = UpstreamResource::class;

    public function getTitle(): string
    {
        return '';
    }
}
