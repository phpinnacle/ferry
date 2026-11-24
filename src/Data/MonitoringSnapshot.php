<?php

namespace PHPinnacle\Ferry\Data;

use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;

final readonly class MonitoringSnapshot
{
    public const string WORKER_METRIC = 'up';

    /** @param list<MonitoringSample> $samples */
    public function __construct(
        public MonitoringStatus $status,
        public array $samples = [],
    ) {}

    /** @param list<MonitoringSample> $samples */
    public static function fromSamples(array $samples, int $now, int $staleAfter): self
    {
        $up = array_filter($samples, fn (MonitoringSample $sample) => $sample->name === self::WORKER_METRIC);

        if ($up === []) {
            return new self(MonitoringStatus::NoData, $samples);
        }

        foreach ($up as $sample) {
            if (($now - $sample->sampledAt) > $staleAfter) {
                return new self(MonitoringStatus::Stale, $samples);
            }

            if ($sample->value !== 1.0) {
                return new self(MonitoringStatus::ScrapeFailed, $samples);
            }
        }

        foreach ($samples as $sample) {
            if (($now - $sample->sampledAt) > $staleAfter) {
                return new self(MonitoringStatus::Stale, $samples);
            }
        }

        return new self(MonitoringStatus::Ready, $samples);
    }

    public function workerUp(): ?float
    {
        if ($this->status !== MonitoringStatus::Ready && $this->status !== MonitoringStatus::ScrapeFailed) {
            return null;
        }

        $values = $this->sampleValues(self::WORKER_METRIC);

        return $values === null ? null : min($values);
    }

    public function value(SourceMetric|SinkMetric $metric): ?float
    {
        if ($this->status !== MonitoringStatus::Ready) {
            return null;
        }

        $values = $this->sampleValues($metric->prometheusName(), $metric->context());

        return $values === null ? null : $metric->aggregate($values);
    }

    public function oldestSampleAt(): ?float
    {
        return $this->samples === []
            ? null
            : min(array_map(fn (MonitoringSample $sample) => $sample->sampledAt, $this->samples));
    }

    public function queueUsed(): ?float
    {
        $total = $this->value(SourceMetric::QueueTotalCapacity);
        $remaining = $this->value(SourceMetric::QueueRemainingCapacity);

        return $total === null || $remaining === null ? null : $total - $remaining;
    }

    public function queuePercent(): ?float
    {
        $total = $this->value(SourceMetric::QueueTotalCapacity);
        $used = $this->queueUsed();

        return $total === null || $total <= 0 || $used === null ? null : ($used / $total) * 100;
    }

    /** @return non-empty-list<float>|null */
    private function sampleValues(string $name, ?string $context = null): ?array
    {
        $values = [];

        foreach ($this->samples as $sample) {
            if ($sample->name !== $name || $sample->context !== $context) {
                continue;
            }

            if ($sample->value === null) {
                return null;
            }

            $values[] = $sample->value;
        }

        return $values === [] ? null : $values;
    }
}
