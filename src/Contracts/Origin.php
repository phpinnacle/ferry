<?php

namespace PHPinnacle\Ferry\Contracts;

use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;

interface Origin
{
    public function name(): string;

    public function make(Upstream|Replica $source): Connector;

    public function create(Connector $connector): void;

    public function remove(Connector $connector): void;

    public function update(Connector $connector): void;

    public function snapshot(Upstream|Replica $owner): void;

    public function start(Connector $connector): void;

    public function pause(Connector $connector): void;

    public function status(Connector $connector): ConnectorStatus;
}
