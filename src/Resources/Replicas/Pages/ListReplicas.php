<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Pages;

use Filament\Resources\Pages\ListRecords;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;

class ListReplicas extends ListRecords
{
    protected static string $resource = ReplicaResource::class;

    public function getTitle(): string
    {
        return '';
    }
}
