<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Pages;

use Filament\Resources\Pages\CreateRecord;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;

class CreateUpstream extends CreateRecord
{
    protected static string $resource = UpstreamResource::class;

    public function getTitle(): string
    {
        return __('phpinnacle-ferry::resources.upstream.pages.create');
    }
}
