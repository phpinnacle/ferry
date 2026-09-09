<?php

namespace PHPinnacle\Ferry\Services\Connectors;

use Illuminate\Support\Facades\Config;
use PHPinnacle\Ferry\Contracts\SignalProducer;
use PHPinnacle\Ferry\Models\Connection;

class SnapshotSignaler
{
    public function __construct(
        private readonly SignalProducer $producer,
        private readonly SourceConnectorConfigBuilder $source,
    ) {}

    /** @param list<string> $tables */
    public function requestSnapshot(Connection $connection, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $payload = json_encode([
            'type' => 'execute-snapshot',
            'data' => [
                'data-collections' => $tables,
                'type' => 'INCREMENTAL',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->producer->publish(
            Config::string('phpinnacle-ferry.kafka_connect.signal.topic'),
            $this->source->signalKey($connection),
            $payload,
        );
    }
}
