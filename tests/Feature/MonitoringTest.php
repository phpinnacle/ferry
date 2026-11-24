<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Auth\Access\Gate as LaravelGate;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Replicas\Pages\EditReplica;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;
use PHPinnacle\Ferry\Resources\Upstreams\Pages\EditUpstream;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Widgets\Monitoring;
use PHPinnacle\Ferry\Widgets\MonitoringChart;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    $panel = Panel::make()
        ->id('monitoring')
        ->path('monitoring')
        ->default()
        ->homeUrl('/monitoring')
        ->resources([UpstreamResource::class, ReplicaResource::class]);
    app(PanelRegistry::class)->register($panel);
    Filament::setCurrentPanel($panel);
    Filament::bootCurrentPanel();
    Route::name('filament.monitoring.')
        ->prefix('monitoring')
        ->group(function () use ($panel) {
            foreach ($panel->getResources() as $resource) {
                $resource::registerRoutes($panel);
            }
        });
    Route::getRoutes()->refreshNameLookups();
    Gate::swap(new LaravelGate(app(), fn () => auth()->user()));
    Gate::before(fn (GenericUser $user) => $user->monitor);
    config([
        'phpinnacle-ferry.origins.kafka.prometheus' => [
            'base_uri' => 'http://widget-prometheus.test',
            'headers' => [],
            'labels' => ['job' => 'connect'],
            'stale_after' => 90,
        ],
    ]);
});

function fake_ferry_widget_metrics(stdClass $state): void
{
    Http::preventStrayRequests();
    $response = function (Request $request) use ($state) {
        $history = str_contains($request->url(), 'query_range');

        if ($history && $state->history === 'failed') {
            return Http::response([], 503);
        }

        if ($history && $state->history === 'empty') {
            return Http::response([
                'status' => 'success',
                'data' => ['resultType' => 'matrix', 'result' => []],
            ]);
        }

        $timestamp = str_contains($request['query'], 'timestamp(');
        $now = now()->timestamp;
        $metrics = $state->sink
            ? [
                ['kafka_connect_sink_task_metrics_sink_record_read_rate', $state->rate],
                ['kafka_connect_sink_task_metrics_sink_record_send_rate', '0.48'],
                ['kafka_connect_sink_task_metrics_sink_record_active_count', '0'],
                ['kafka_connect_sink_task_metrics_put_batch_avg_time_ms', '18'],
                ['kafka_connect_sink_task_metrics_put_batch_max_time_ms', '41'],
                ['kafka_connect_task_error_metrics_total_record_errors', '0'],
            ] : [
                ['kafka_connect_source_task_metrics_source_record_poll_rate', $state->rate],
                ['kafka_connect_source_task_metrics_source_record_write_rate', '0.48'],
                ['debezium_postgres_connector_metrics_millisecondsbehindsource', '52', 'streaming'],
                ['debezium_postgres_connector_metrics_connected', '1', 'streaming'],
                ['debezium_postgres_connector_metrics_millisecondssincelastevent', '45692', 'streaming'],
                ['debezium_postgres_connector_metrics_totalnumberofeventsseen', '51923', 'streaming'],
                ['debezium_postgres_connector_metrics_queuetotalcapacity', '8192', 'streaming'],
                ['debezium_postgres_connector_metrics_queueremainingcapacity', '8192', 'streaming'],
                ['debezium_postgres_connector_metrics_snapshotcompleted', '1', 'snapshot'],
            ];
        $items = [['up', '1'], ...($history ? array_slice($metrics, offset: 0, length: 3) : $metrics)];
        $result = array_map(fn (array $item) => [
            'metric' => ['ferry_metric' => $item[0], ...(($item[2] ?? null) !== null ? ['context' => $item[2]] : [])],
            ...(
                $history
                    ? [
                        'values' => [
                            [$now - 30, $timestamp ? (string) ($now - 40) : $item[1]],
                            [$now, $timestamp ? (string) ($now - 10) : $item[1]],
                        ],
                    ] : ['value' => [$now, $timestamp ? (string) ($now - 10) : $item[1]]]
            ),
        ], $items);

        return Http::response([
            'status' => 'success',
            'data' => ['resultType' => $history ? 'matrix' : 'vector', 'result' => $result],
        ]);
    };
    Http::fake([
        'widget-prometheus.test/api/v1/query' => $response,
        'widget-prometheus.test/api/v1/query_range' => $response,
    ]);
}

function ferry_monitored_record(string $kind): Upstream|Replica
{
    if ($kind === 'replica') {
        $replica = TestCase::makeReplica();
        TestCase::makeConnector($replica, ['name' => 'sink']);

        return $replica;
    }

    $upstream = TestCase::makeUpstream();
    TestCase::makeConnector($upstream, ['name' => 'source', 'config' => ['topic.prefix' => 'source']])->markApplied();

    return $upstream;
}

it('renders and refreshes source stages and two native charts from real monitoring data', function () {
    Queue::fake();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    $state = (object) ['rate' => '0.37', 'sink' => false, 'history' => 'ready'];
    fake_ferry_widget_metrics($state);
    $upstream = ferry_monitored_record('upstream');

    $widget = Livewire::test(Monitoring::class, ['record' => $upstream])
        ->assertSee('Worker scrape available')
        ->assertSee('2027-01-15T07:59:50+00:00')
        ->assertSeeHtml('wire:poll.30s="$refresh"')
        ->assertSeeInOrder(['Source DB', 'Debezium', 'Kafka'])
        ->assertSee('0.37 /s')
        ->assertSee('52.00 ms')
        ->assertSee('0 / 8 192')
        ->assertSee('0.0%')
        ->assertSee('51 923')
        ->assertSeeLivewire(MonitoringChart::class)
        ->assertViewHas(
            'charts',
            fn (array $charts) => count($charts) === 2 && $charts[1]['data']['datasets'][0]['data'][30] === 0.37,
        );
    Http::assertSentCount(4);
    Http::assertSent(
        fn (Request $request) => (
            str_contains($request->url(), 'query_range')
            && str_contains($request['query'], 'millisecondsbehindsource')
            && str_contains($request['query'], 'source_record_poll_rate')
            && str_contains($request['query'], 'source_record_write_rate')
            && !str_contains($request['query'], 'queuetotalcapacity')
        ),
    );

    $state->rate = '0.75';
    $widget
        ->call('$refresh')
        ->assertSee('0.75 /s')
        ->assertDontSee('0.37 /s')
        ->assertViewHas('charts', fn (array $charts) => $charts[1]['data']['datasets'][0]['data'][30] === 0.75);
    Http::assertSentCount(8);
});

it('keeps current metrics when history is unavailable or empty and mounts charts when history recovers', function () {
    Queue::fake();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    $state = (object) ['rate' => '0.37', 'sink' => false, 'history' => 'failed'];
    fake_ferry_widget_metrics($state);
    $upstream = ferry_monitored_record('upstream');

    $widget = Livewire::test(Monitoring::class, ['record' => $upstream])
        ->assertSee('0.37 /s')
        ->assertSee('Metric history is unavailable.')
        ->assertDontSee('0.37 / 0.48 /s')
        ->assertDontSeeLivewire(MonitoringChart::class);

    $state->history = 'empty';
    $widget
        ->call('$refresh')
        ->assertSee('0.37 /s')
        ->assertSee('No historical samples for these metrics.')
        ->assertDontSee('Metric history is unavailable.')
        ->assertDontSee('0.37 / 0.48 /s')
        ->assertDontSeeLivewire(MonitoringChart::class);

    $state->history = 'ready';
    $widget
        ->call('$refresh')
        ->assertDontSee('No historical samples for these metrics.')
        ->assertSee('0.37 / 0.48 /s')
        ->assertViewHas('historyAvailable', true);
    Http::assertSentCount(11);
});

it('shows sink throughput batch timing and the unmeasured destination boundary', function () {
    Queue::fake();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    $state = (object) ['rate' => '0.48', 'sink' => true, 'history' => 'ready'];
    fake_ferry_widget_metrics($state);
    $replica = ferry_monitored_record('replica');

    Livewire::test(Monitoring::class, ['record' => $replica])
        ->assertSee('Worker scrape available')
        ->assertSee('2027-01-15T07:59:50+00:00')
        ->assertSeeInOrder(['Kafka', 'Sink connector', 'Destination DB'])
        ->assertSee('Not measured')
        ->assertSee('18.00 ms')
        ->assertSee('41.00 ms')
        ->assertSee('Errors & retries')
        ->assertDontSee('Queue used / capacity')
        ->assertSeeLivewire(MonitoringChart::class);
    Http::assertSentCount(4);
});

it('reauthorizes polling before contacting Prometheus', function (string $kind) {
    Queue::fake();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $user = new GenericUser(['id' => 1, 'monitor' => true]);
    $this->actingAs($user);
    fake_ferry_widget_metrics((object) ['rate' => '0.37', 'sink' => $kind === 'replica', 'history' => 'ready']);
    $record = ferry_monitored_record($kind);
    $widget = Livewire::test(Monitoring::class, ['record' => $record]);
    $user->monitor = false;

    $widget->call('$refresh')->assertForbidden();
    Http::assertSentCount(4);
})->with(['upstream', 'replica']);

it('reapplies the resource query when a record leaves the visible scope', function (string $kind) {
    Queue::fake();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    fake_ferry_widget_metrics((object) ['rate' => '0.37', 'sink' => $kind === 'replica', 'history' => 'ready']);
    $record = ferry_monitored_record($kind);
    $widget = Livewire::test(Monitoring::class, ['record' => $record]);
    $scopes = Upstream::getAllGlobalScopes();

    try {
        $record::addGlobalScope('monitoring-test', fn (Builder $query) => $query->where('id', '<>', $record->id));

        $widget->call('$refresh')->assertNotFound();
    } finally {
        Upstream::setAllGlobalScopes($scopes);
    }

    Http::assertSentCount(4);
})->with(['upstream', 'replica']);

it('hides monitoring and skips Prometheus requests when an origin is disabled', function (
    string $kind,
    ?string $baseUri,
) {
    Queue::fake();
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    config(['phpinnacle-ferry.origins.kafka.prometheus.base_uri' => $baseUri]);
    Http::preventStrayRequests();
    $record = ferry_monitored_record($kind);

    Livewire::test($kind === 'replica' ? EditReplica::class : EditUpstream::class, ['record' => $record->getRouteKey()])
        ->assertDontSeeLivewire(Monitoring::class)
        ->assertDontSeeHtml('wire:poll.30s');
    Http::assertNothingSent();
})->with(['upstream', 'replica'])->with(['null' => [null], 'empty' => ['']]);

it('shows monitoring on edit pages for an origin with a Prometheus URL', function (string $kind) {
    Queue::fake();
    $this->actingAs(new GenericUser(['id' => 1, 'monitor' => true]));
    Http::preventStrayRequests();
    $record = ferry_monitored_record($kind);

    Livewire::test($kind === 'replica' ? EditReplica::class : EditUpstream::class, ['record' => $record->getRouteKey()])
        ->assertSeeLivewire(Monitoring::class);
    Http::assertNothingSent();
})->with(['upstream', 'replica']);
