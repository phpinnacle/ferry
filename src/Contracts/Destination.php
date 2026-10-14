<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\TransformFactory;

interface Destination
{
    public string $table { get; }

    /** @return array<string, string> */
    public function connector(
        Sync $sync,
        ConnectionMetadata $object,
        string $topic,
        TransformFactory $transforms,
    ): array;
}
