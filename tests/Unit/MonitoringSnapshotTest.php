<?php

use PHPinnacle\Ferry\Data\MonitoringSample;
use PHPinnacle\Ferry\Data\MonitoringSnapshot;
use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SourceMetric;

it('reports queue occupancy without turning missing capacity into a percentage', function (
    ?float $capacity,
    ?float $remaining,
    ?float $used,
    ?float $percent,
) {
    $snapshot = new MonitoringSnapshot(MonitoringStatus::Ready, [
        new MonitoringSample('up', null, 1.0, 1000.0),
        new MonitoringSample(SourceMetric::QueueTotalCapacity->prometheusName(), 'streaming', $capacity, 1000.0),
        new MonitoringSample(SourceMetric::QueueRemainingCapacity->prometheusName(), 'streaming', $remaining, 1000.0),
    ]);

    expect($snapshot->queueUsed())->toBe($used);
    expect($snapshot->queuePercent())->toBe($percent);
})->with([
    'partially occupied' => [1000.0, 750.0, 250.0, 25.0],
    'empty' => [8192.0, 8192.0, 0.0, 0.0],
    'zero capacity' => [0.0, 0.0, 0.0, null],
    'remaining capacity absent' => [8192.0, null, null, null],
    'total capacity absent' => [null, 750.0, null, null],
]);

it('determines snapshot status precedence from worker and metric samples', function (
    array $samples,
    MonitoringStatus $status,
    ?float $workerUp,
) {
    $snapshot = MonitoringSnapshot::fromSamples($samples, now: 1000, staleAfter: 90);

    expect($snapshot->status)->toBe($status);
    expect($snapshot->workerUp())->toBe($workerUp);
})->with([
    'exact stale boundary is ready' => [
        [
            new MonitoringSample('up', null, 1.0, 910.0),
            new MonitoringSample(SourceMetric::PollRate->prometheusName(), null, 5.0, 910.0),
        ],
        MonitoringStatus::Ready,
        1.0,
    ],
    'stale worker beats failed scrape' => [
        [new MonitoringSample('up', null, 0.0, 909.0)],
        MonitoringStatus::Stale,
        null,
    ],
    'failed scrape beats stale metric' => [
        [
            new MonitoringSample('up', null, 0.0, 990.0),
            new MonitoringSample(SourceMetric::PollRate->prometheusName(), null, 5.0, 800.0),
        ],
        MonitoringStatus::ScrapeFailed,
        0.0,
    ],
    'any failed worker fails scrape' => [
        [new MonitoringSample('up', null, 1.0, 990.0), new MonitoringSample('up', null, 0.0, 990.0)],
        MonitoringStatus::ScrapeFailed,
        0.0,
    ],
    'nonfinite worker value fails scrape' => [
        [new MonitoringSample('up', null, null, 990.0)],
        MonitoringStatus::ScrapeFailed,
        null,
    ],
]);
