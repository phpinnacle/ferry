<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;

interface Destination
{
    public string $table { get; }

    /** @return array<string, string> */
    public function connector(Sync $sync, ConnectionMetadata $object, string $topic): array;
}
