<?php

namespace PHPinnacle\Ferry\Contracts;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\TransformFactory;

interface Destination extends HasLabel, HasIcon, HasColor
{
    /** @return array<string, string> */
    public function connector(
        Sync $sync,
        ConnectionMetadata $object,
        string $topic,
        TransformFactory $transforms,
    ): array;
}
