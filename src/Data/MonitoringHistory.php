<?php

namespace PHPinnacle\Ferry\Data;

use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;

final readonly class MonitoringHistory
{
    /** @param array<int, MonitoringSnapshot> $snapshots Evaluation timestamps in UTC. */
    public function __construct(
        public array $snapshots = [],
        public bool $available = true,
    ) {}

    /** @return list<float|null> */
    public function values(SourceMetric|SinkMetric $metric): array
    {
        return array_values(array_map(fn (MonitoringSnapshot $snapshot) => $snapshot->value(
            $metric,
        ), $this->snapshots));
    }
}
