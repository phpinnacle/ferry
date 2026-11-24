<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPinnacle\Ferry\Data\MonitoringSnapshot;
use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\PrometheusClient;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    Http::preventStrayRequests();
    config([
        'phpinnacle-ferry.origins.primary.prometheus' => [
            'base_uri' => 'http://prometheus.test',
            'headers' => ['Authorization' => 'Bearer test-token'],
            'labels' => ['namespace' => 'primary', 'job' => 'connect'],
            'stale_after' => 90,
        ],
    ]);
});

function monitored_connector(string $origin = 'primary'): Connector
{
    return new Connector()->forceFill([
        'name' => 'shared-connector',
        'origin' => $origin,
        'config' => ['topic.prefix' => 'pending-server'],
        'applied_config' => ['topic.prefix' => 'applied.server'],
    ]);
}

/**
 * @param list<array{name: string, value: string, at?: int|string, labels?: array<string, string>}> $series
 * @return array{status: string, data: array{resultType: string, result: list<array{metric: array<string, string>, value: array{int, string}}>}}
 */
function monitoring_response(array $series, bool $timestamps): array
{
    return [
        'status' => 'success',
        'data' => [
            'resultType' => 'vector',
            'result' => array_map(
                fn (array $sample) => [
                    'metric' => [
                        ...($timestamps ? [] : ['__name__' => $sample['name']]),
                        'ferry_metric' => $sample['name'],
                        ...($sample['labels'] ?? []),
                    ],
                    'value' => [
                        1_800_000_000,
                        $timestamps ? (string) ($sample['at'] ?? 1_799_999_980) : $sample['value'],
                    ],
                ],
                $series,
            ),
        ],
    ];
}

/** @param list<array{name: string, value: string, at?: int|string, labels?: array<string, string>}> $series */
function fake_monitoring(array $series): void
{
    Http::fake([
        'prometheus.test/api/v1/query' => fn (Request $request) => Http::response(
            monitoring_response($series, str_contains($request['query'], 'timestamp(')),
        ),
    ]);
}

/** @param list<array{name: string, values: list<array{int, string}>, labels?: array<string, string>, timestamps?: list<array{int, string}>}> $series */
function fake_monitoring_history(array $series): void
{
    Http::fake(['prometheus.test/api/v1/query_range' => function (Request $request) use ($series) {
        $timestamps = str_contains($request['query'], 'timestamp(');

        return Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'matrix',
                'result' => array_map(
                    fn (array $item) => [
                        'metric' => ['ferry_metric' => $item['name'], ...($item['labels'] ?? [])],
                        'values' => $timestamps
                            ? $item['timestamps'] ?? array_map(fn (array $value) => [
                                $value[0],
                                (string) ($value[0] - 10),
                            ], $item['values'])
                            : $item['values'],
                    ],
                    $series,
                ),
            ],
        ]);
    }]);
}

it('maps applied source identity and keeps streaming separate from snapshot', function () {
    fake_monitoring([
        ['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1'],
        ['name' => SourceMetric::Connected->prometheusName(), 'value' => '1', 'labels' => ['context' => 'streaming']],
        [
            'name' => SourceMetric::SnapshotConnected->prometheusName(),
            'value' => '1',
            'labels' => ['context' => 'snapshot', 'task' => '0'],
        ],
        [
            'name' => SourceMetric::SnapshotConnected->prometheusName(),
            'value' => '0',
            'labels' => ['context' => 'snapshot', 'task' => '1'],
        ],
        [
            'name' => SourceMetric::SourceLag->prometheusName(),
            'value' => '-40',
            'at' => 1_799_999_910,
            'labels' => ['context' => 'streaming', 'task' => '0'],
        ],
        [
            'name' => SourceMetric::SourceLag->prometheusName(),
            'value' => '-25',
            'labels' => ['context' => 'streaming', 'task' => '1'],
        ],
        [
            'name' => SourceMetric::LastEvent->prometheusName(),
            'value' => '100',
            'labels' => ['context' => 'streaming', 'task' => '0'],
        ],
        [
            'name' => SourceMetric::LastEvent->prometheusName(),
            'value' => '600000',
            'labels' => ['context' => 'streaming', 'task' => '1'],
        ],
        [
            'name' => SourceMetric::Events->prometheusName(),
            'value' => '120',
            'labels' => ['context' => 'streaming'],
        ],
        [
            'name' => SourceMetric::Events->prometheusName(),
            'value' => '900',
            'labels' => ['context' => 'snapshot'],
        ],
        [
            'name' => SourceMetric::QueueTotalCapacity->prometheusName(),
            'value' => '100',
            'labels' => ['context' => 'streaming'],
        ],
        [
            'name' => SourceMetric::QueueRemainingCapacity->prometheusName(),
            'value' => '70',
            'labels' => ['context' => 'streaming'],
        ],
        [
            'name' => SourceMetric::SnapshotRunning->prometheusName(),
            'value' => '0',
            'labels' => ['context' => 'snapshot'],
        ],
        [
            'name' => SourceMetric::SnapshotCompleted->prometheusName(),
            'value' => '1',
            'labels' => ['context' => 'snapshot'],
        ],
        [
            'name' => SourceMetric::SnapshotAborted->prometheusName(),
            'value' => '0',
            'labels' => ['context' => 'snapshot'],
        ],
        ['name' => SourceMetric::PollRate->prometheusName(), 'value' => '10', 'labels' => ['task' => '0']],
        ['name' => SourceMetric::PollRate->prometheusName(), 'value' => '15', 'labels' => ['task' => '1']],
        ['name' => SourceMetric::WriteRate->prometheusName(), 'value' => '24'],
    ]);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SourceMetric::cases());

    expect($snapshot->status)->toBe(MonitoringStatus::Ready);
    expect($snapshot->oldestSampleAt())->toBe(1_799_999_910.0);
    expect($snapshot->workerUp())->toBe(1.0);
    expect($snapshot->value(SourceMetric::Connected))->toBe(1.0);
    expect($snapshot->value(SourceMetric::SnapshotConnected))->toBe(0.0);
    expect($snapshot->value(SourceMetric::SourceLag))->toBe(-25.0);
    expect($snapshot->value(SourceMetric::LastEvent))->toBe(600_000.0);
    expect($snapshot->value(SourceMetric::Events))->toBe(120.0);
    expect($snapshot->value(SourceMetric::QueueTotalCapacity))->toBe(100.0);
    expect($snapshot->value(SourceMetric::QueueRemainingCapacity))->toBe(70.0);
    expect($snapshot->value(SourceMetric::PollRate))->toBe(25.0);
    expect($snapshot->value(SourceMetric::WriteRate))->toBe(24.0);
    expect($snapshot->value(SourceMetric::SnapshotRunning))->toBe(0.0);
    expect($snapshot->value(SourceMetric::SnapshotCompleted))->toBe(1.0);
    expect($snapshot->value(SourceMetric::SnapshotAborted))->toBe(0.0);
    Http::assertSentCount(2);
    Http::assertSent(
        fn (Request $request) => (
            $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($request['query'], 'connector="shared-connector"')
            && str_contains($request['query'], 'server="applied.server"')
            && str_contains($request['query'], 'context=~"streaming|snapshot"')
            && !str_contains($request['query'], 'pending-server')
        ),
    );
});

it('omits Debezium selectors when applied config has no topic prefix', function () {
    $connector = monitored_connector()->forceFill(['applied_config' => []]);
    fake_monitoring([
        ['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1'],
        ['name' => SourceMetric::PollRate->prometheusName(), 'value' => '12'],
    ]);

    $snapshot = app(PrometheusClient::class)->snapshot($connector, SourceMetric::cases());

    expect($snapshot->status)->toBe(MonitoringStatus::Ready);
    expect($snapshot->value(SourceMetric::PollRate))->toBe(12.0);
    expect($snapshot->value(SourceMetric::Connected))->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(
        fn (Request $request) => (
            str_contains($request['query'], 'connector="shared-connector"')
            && !str_contains($request['query'], 'debezium_postgres_connector_metrics_')
            && !str_contains($request['query'], 'server=')
            && !str_contains($request['query'], 'pending-server')
        ),
    );
});

it('sums task rates and counts but averages batch means and takes the maximum batch time', function () {
    $series = [['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1']];

    foreach ([
        SinkMetric::ReadRate->prometheusName() => ['10', '20'],
        SinkMetric::SendRate->prometheusName() => ['8', '12'],
        SinkMetric::Active->prometheusName() => ['2', '8'],
        SinkMetric::BatchAvg->prometheusName() => ['10', '30'],
        SinkMetric::BatchMax->prometheusName() => ['25', '50'],
        SinkMetric::Errors->prometheusName() => ['1', '2'],
        SinkMetric::Failures->prometheusName() => ['3', '4'],
        SinkMetric::Skipped->prometheusName() => ['5', '6'],
        SinkMetric::Retries->prometheusName() => ['7', '8'],
    ] as $name => $values) {
        foreach ($values as $task => $value) {
            $series[] = ['name' => $name, 'value' => $value, 'labels' => ['task' => (string) $task]];
        }
    }

    fake_monitoring($series);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SinkMetric::cases());

    expect($snapshot->value(SinkMetric::ReadRate))->toBe(30.0);
    expect($snapshot->value(SinkMetric::SendRate))->toBe(20.0);
    expect($snapshot->value(SinkMetric::Active))->toBe(10.0);
    expect($snapshot->value(SinkMetric::BatchAvg))->toBe(20.0);
    expect($snapshot->value(SinkMetric::BatchMax))->toBe(50.0);
    expect($snapshot->value(SinkMetric::Errors))->toBe(3.0);
    expect($snapshot->value(SinkMetric::Failures))->toBe(7.0);
    expect($snapshot->value(SinkMetric::Skipped))->toBe(11.0);
    expect($snapshot->value(SinkMetric::Retries))->toBe(15.0);
    Http::assertSentCount(2);
});

it('labels each metric before union and matches its original timestamp when labels coincide', function () {
    $labels = ['namespace' => 'primary', 'job' => 'connect', 'connector' => 'shared-connector', 'task' => '0'];
    $series = [
        ['name' => 'up', 'value' => '1'],
        [
            'name' => 'kafka_connect_sink_task_metrics_sink_record_read_rate',
            'value' => '10',
            'at' => 1_799_999_970,
            'labels' => $labels,
        ],
        [
            'name' => 'kafka_connect_sink_task_metrics_sink_record_send_rate',
            'value' => '8',
            'at' => 1_799_999_960,
            'labels' => $labels,
        ],
    ];
    Http::fake(['prometheus.test/api/v1/query' => function (Request $request) use ($series) {
        $timestamps = str_contains($request['query'], 'timestamp(');

        foreach (explode(' or ', $request['query']) as $operand) {
            expect($operand)
                ->toStartWith($timestamps ? 'label_replace(timestamp({' : 'label_replace({')
                ->toContain('"ferry_metric"');
        }

        expect($request['time'])->toBe(1_800_000_000);

        return Http::response(monitoring_response($timestamps ? array_reverse($series) : $series, $timestamps));
    }]);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SinkMetric::cases());

    expect($snapshot->status)->toBe(MonitoringStatus::Ready);
    expect($snapshot->value(SinkMetric::ReadRate))->toBe(10.0);
    expect($snapshot->value(SinkMetric::SendRate))->toBe(8.0);
    expect(array_column($snapshot->samples, 'sampledAt', 'name'))->toBe([
        'up' => 1_799_999_980.0,
        SinkMetric::ReadRate->prometheusName() => 1_799_999_970.0,
        SinkMetric::SendRate->prometheusName() => 1_799_999_960.0,
    ]);
    Http::assertSentCount(2);
});

it('distinguishes zeros from missing and nonfinite values', function () {
    fake_monitoring([
        ['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1'],
        ['name' => SinkMetric::ReadRate->prometheusName(), 'value' => '0'],
        ['name' => SinkMetric::BatchAvg->prometheusName(), 'value' => 'NaN'],
        ['name' => SinkMetric::BatchMax->prometheusName(), 'value' => '+Inf'],
        ['name' => SinkMetric::Active->prometheusName(), 'value' => '5', 'labels' => ['task' => '0']],
        ['name' => SinkMetric::Active->prometheusName(), 'value' => 'NaN', 'labels' => ['task' => '1']],
    ]);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SinkMetric::cases());

    expect($snapshot->value(SinkMetric::ReadRate))->toBe(0.0);
    expect($snapshot->value(SinkMetric::SendRate))->toBeNull();
    expect($snapshot->value(SinkMetric::BatchAvg))->toBeNull();
    expect($snapshot->value(SinkMetric::BatchMax))->toBeNull();
    expect($snapshot->value(SinkMetric::Active))->toBeNull();
    Http::assertSentCount(2);
});

it('reports missing scrape data without inventing zero metrics', function (array $series, ?float $oldestSampleAt) {
    fake_monitoring($series);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SourceMetric::cases());

    expect($snapshot->status)->toBe(MonitoringStatus::NoData);
    expect($snapshot->oldestSampleAt())->toBe($oldestSampleAt);
    expect($snapshot->workerUp())->toBeNull();
    expect($snapshot->value(SourceMetric::PollRate))->toBeNull();
    Http::assertSentCount(2);
})->with([
    'empty result' => [[], null],
    'missing worker series' => [
        [['name' => SourceMetric::PollRate->prometheusName(), 'value' => '10']],
        1_799_999_980.0,
    ],
]);

it('withholds values when a scrape timestamp is stale despite a fresh evaluation time', function () {
    fake_monitoring([
        ['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1'],
        ['name' => SinkMetric::ReadRate->prometheusName(), 'value' => '10', 'at' => 1_799_999_909],
    ]);

    $snapshot = app(PrometheusClient::class)->snapshot(monitored_connector(), SinkMetric::cases());

    expect($snapshot->status)->toBe(MonitoringStatus::Stale);
    expect($snapshot->value(SinkMetric::ReadRate))->toBeNull();
    Http::assertSentCount(2);
});

it('isolates identically named connectors and Debezium servers using each origins filters', function () {
    config([
        'phpinnacle-ferry.origins.secondary.prometheus' => [
            'base_uri' => 'http://prometheus.test',
            'headers' => [],
            'labels' => ['namespace' => 'secondary', 'job' => 'other-connect'],
            'stale_after' => 90,
        ],
    ]);
    Http::fake(['prometheus.test/api/v1/query' => function (Request $request) {
        $primary = str_contains($request['query'], 'namespace="primary",job="connect"');
        $secondary = str_contains($request['query'], 'namespace="secondary",job="other-connect"');
        expect($primary xor $secondary)->toBeTrue();

        return Http::response(monitoring_response(
            [
                ['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1'],
                [
                    'name' => SourceMetric::Events->prometheusName(),
                    'value' => $primary ? '10' : '70',
                    'labels' => ['context' => 'streaming'],
                ],
            ],
            str_contains($request['query'], 'timestamp('),
        ));
    }]);

    $primary = app(PrometheusClient::class)->snapshot(monitored_connector(), SourceMetric::cases());
    $secondary = app(PrometheusClient::class)->snapshot(monitored_connector('secondary'), SourceMetric::cases());

    expect($primary->value(SourceMetric::Events))->toBe(10.0);
    expect($secondary->value(SourceMetric::Events))->toBe(70.0);
    Http::assertSentCount(4);
});

it('escapes connector names server names and origin label values in PromQL', function () {
    $connector = monitored_connector()->forceFill([
        'name' => "connector\"\\\n",
        'applied_config' => ['topic.prefix' => "server\"\\\n"],
    ]);
    config(['phpinnacle-ferry.origins.primary.prometheus.labels' => [
        'job' => "job\"\\\n",
        'service' => "service\t\r/",
    ]]);
    fake_monitoring([]);

    app(PrometheusClient::class)->snapshot($connector, SourceMetric::cases());

    Http::assertSent(
        fn (Request $request) => (
            str_contains($request['query'], 'connector="connector\\"\\\\\\n"')
            && str_contains($request['query'], 'server="server\\"\\\\\\n"')
            && str_contains($request['query'], 'job="job\\"\\\\\\n"')
            && str_contains($request['query'], 'service="service\\t\\r/"')
        ),
    );
});

it('makes no requests without a connector or Prometheus configuration', function () {
    config([
        'phpinnacle-ferry.origins.configured.prometheus' => [
            'base_uri' => 'http://prometheus.test',
            'headers' => [],
            'labels' => [],
            'stale_after' => 90,
        ],
        'phpinnacle-ferry.origins.primary.prometheus.base_uri' => null,
        'phpinnacle-ferry.origins.empty.prometheus' => [
            'base_uri' => '',
            'headers' => [],
            'labels' => [],
            'stale_after' => 90,
        ],
    ]);

    $client = app(PrometheusClient::class);

    expect($client->isEnabled('configured'))->toBeTrue();
    expect($client->isEnabled('primary'))->toBeFalse();
    expect($client->isEnabled('empty'))->toBeFalse();
    expect($client->isEnabled('unconfigured'))->toBeFalse();
    expect($client->snapshot(null, SourceMetric::cases())->status)->toBe(MonitoringStatus::NoConnector);
    expect($client->snapshot(monitored_connector(), SourceMetric::cases())->status)->toBe(MonitoringStatus::Disabled);
    expect($client->snapshot(monitored_connector('empty'), SourceMetric::cases())->status)
        ->toBe(MonitoringStatus::Disabled);
    expect($client->snapshot(monitored_connector('unconfigured'), SinkMetric::cases())->status)
        ->toBe(MonitoringStatus::Disabled);
    expect($client->history(null, SourceMetric::cases())->available)->toBeFalse();
    expect($client->history(monitored_connector('unconfigured'), SinkMetric::cases())->available)->toBeFalse();
    Http::assertNothingSent();
});

it('contains connection failures so connector pages can continue working', function () {
    Http::fake(['prometheus.test/api/v1/*' => Http::failedConnection()]);
    $client = app(PrometheusClient::class);

    expect($client->snapshot(monitored_connector(), SourceMetric::cases())->status)
        ->toBe(MonitoringStatus::Unavailable);
    expect($client->history(monitored_connector(), SourceMetric::cases())->available)->toBeFalse();
    Http::assertSentCount(2);
});

it('rejects unsuccessful or malformed external responses', function (mixed $body, int $status) {
    Http::fake(['prometheus.test/api/v1/query' => Http::response($body, $status)]);

    expect(app(PrometheusClient::class)->snapshot(monitored_connector(), SourceMetric::cases())->status)
        ->toBe(MonitoringStatus::Unavailable);
    Http::assertSentCount(1);
})->with([
    'HTTP error' => [['error' => 'unavailable'], 503],
    'API error' => [['status' => 'error', 'error' => 'bad query'], 200],
    'invalid JSON' => ['{broken', 200],
    'wrong result type' => [['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]], 200],
    'invalid labels' => [
        [
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => [['metric' => ['ferry_metric' => 'up', 'job' => []], 'value' => [1_800_000_000, '1']]],
            ],
        ],
        200,
    ],
    'missing metric name' => [
        [
            'status' => 'success',
            'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1_800_000_000, '1']]]],
        ],
        200,
    ],
    'invalid number' => [
        [
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => [['metric' => ['ferry_metric' => 'up'], 'value' => [1_800_000_000, 'broken']]],
            ],
        ],
        200,
    ],
]);

it('rejects missing, nonfinite, negative, or future sample timestamps', function (array $timestamps) {
    Http::fake(['prometheus.test/api/v1/query' => Http::sequence()
        ->push(monitoring_response([['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1']], false))
        ->push(monitoring_response($timestamps, true))]);

    expect(app(PrometheusClient::class)->snapshot(monitored_connector(), SourceMetric::cases())->status)
        ->toBe(MonitoringStatus::Unavailable);
    Http::assertSentCount(2);
})->with([
    'missing timestamp' => [[]],
    'nonfinite timestamp' => [[['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1', 'at' => 'NaN']]],
    'negative timestamp' => [[['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1', 'at' => -1]]],
    'future timestamp' => [[['name' => MonitoringSnapshot::WORKER_METRIC, 'value' => '1', 'at' => 1_800_000_001]]],
]);

it('reads a bounded source history with task aggregation zeros negative lag and explicit gaps', function () {
    fake_monitoring_history([
        ['name' => 'up', 'values' => [[1_799_999_100, '1'], [1_799_999_130, '1'], [1_799_999_190, '1']]],
        [
            'name' => SourceMetric::SourceLag->prometheusName(),
            'labels' => ['context' => 'streaming'],
            'values' => [
                [1_799_999_100, '-25'],
                [1_799_999_130, '0'],
                [1_799_999_190, 'NaN'],
            ],
        ],
        [
            'name' => SourceMetric::PollRate->prometheusName(),
            'labels' => ['task' => '0'],
            'values' => [[1_799_999_100, '2'], [1_799_999_130, '0']],
        ],
        [
            'name' => SourceMetric::PollRate->prometheusName(),
            'labels' => ['task' => '1'],
            'values' => [[1_799_999_100, '3'], [1_799_999_130, '0']],
        ],
        ['name' => SourceMetric::WriteRate->prometheusName(), 'values' => [[1_799_999_100, '4'], [1_799_999_130, '0']]],
    ]);

    $history = app(PrometheusClient::class)->history(monitored_connector(), [
        SourceMetric::SourceLag,
        SourceMetric::PollRate,
        SourceMetric::WriteRate,
    ]);

    expect($history->available)->toBeTrue();
    expect($history->snapshots)->toHaveCount(31)->toHaveKeys([1_799_999_100, 1_800_000_000]);
    expect(array_slice($history->values(SourceMetric::SourceLag), offset: 0, length: 4))->toBe([
        -25.0,
        0.0,
        null,
        null,
    ]);
    expect(array_slice($history->values(SourceMetric::PollRate), offset: 0, length: 3))->toBe([5.0, 0.0, null]);
    expect(array_slice($history->values(SourceMetric::WriteRate), offset: 0, length: 3))->toBe([4.0, 0.0, null]);
    Http::assertSentCount(2);
    Http::assertSent(
        fn (Request $request) => (
            $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['start'] === 1_799_999_100
            && $request['end'] === 1_800_000_000
            && $request['step'] === 30
            && str_contains($request['query'], 'namespace="primary"')
            && str_contains($request['query'], 'connector="shared-connector"')
            && str_contains($request['query'], 'server="applied.server"')
            && str_contains($request['query'], 'context="streaming"')
            && !str_contains($request['query'], 'snapshot')
        ),
    );
});

it('uses original scrape timestamps and worker state at each historical point', function () {
    fake_monitoring_history([
        [
            'name' => 'up',
            'values' => [[1_799_999_100, '1'], [1_799_999_130, '0'], [1_799_999_160, '1']],
            'timestamps' => [
                [1_799_999_100, '1799998990'],
                [1_799_999_130, '1799999120'],
                [1_799_999_160, '1799999150'],
            ],
        ],
        [
            'name' => SourceMetric::PollRate->prometheusName(),
            'values' => [[1_799_999_100, '10'], [1_799_999_130, '20'], [1_799_999_160, '30']],
        ],
    ]);

    $history = app(PrometheusClient::class)->history(monitored_connector(), [SourceMetric::PollRate]);

    expect(array_slice($history->values(SourceMetric::PollRate), offset: 0, length: 4))->toBe([null, null, 30.0, null]);
    expect($history->snapshots[1_799_999_100]->status)->toBe(MonitoringStatus::Stale);
    expect($history->snapshots[1_799_999_130]->status)->toBe(MonitoringStatus::ScrapeFailed);
    Http::assertSentCount(2);
});

it('reports unavailable history for invalid external matrices', function (array $response) {
    Http::fake(['prometheus.test/api/v1/query_range' => Http::response($response)]);

    expect(app(PrometheusClient::class)->history(monitored_connector(), SourceMetric::cases())->available)
        ->toBeFalse();
    Http::assertSentCount(1);
})->with([
    'wrong result type' => [['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]],
    'off-grid timestamp' => [[
        'status' => 'success',
        'data' => [
            'resultType' => 'matrix',
            'result' => [['metric' => ['ferry_metric' => 'up'], 'values' => [[1_799_999_115, '1']]]],
        ],
    ]],
    'out-of-range timestamp' => [[
        'status' => 'success',
        'data' => [
            'resultType' => 'matrix',
            'result' => [['metric' => ['ferry_metric' => 'up'], 'values' => [[1_800_000_030, '1']]]],
        ],
    ]],
]);
