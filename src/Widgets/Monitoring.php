<?php

namespace PHPinnacle\Ferry\Widgets;

use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Livewire\Attributes\Locked;
use PHPinnacle\Ferry\Data\MonitoringHistory;
use PHPinnacle\Ferry\Data\MonitoringSnapshot;
use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Replicas\ReplicaResource;
use PHPinnacle\Ferry\Resources\Upstreams\UpstreamResource;
use PHPinnacle\Ferry\Services\PrometheusClient;

/**
 * @phpstan-type StageView array{title: string, icon: Heroicon, status: string, value: string, label: string}
 * @phpstan-type ChartView array{title: string, summary: string, unit: string, data: array{labels: list<string>, datasets: list<array{label: string, data: list<float|null>, borderDash?: list<int>, borderColor?: string}>}}
 * @phpstan-type ModeView array{stages: list<StageView>, charts: list<ChartView>, historyAvailable: bool, queue: array{text: string, percent: ?float}|null, summary: array<string, string>, detailsHeading: string, details: array<string, string>, detailsNote: string, note: string}
 */
class Monitoring extends Widget
{
    #[Locked]
    public Upstream|Replica $record;

    protected string $view = 'phpinnacle-ferry::widgets.monitoring';

    protected int|string|array $columnSpan = 'full';

    protected PrometheusClient $client;

    public function boot(PrometheusClient $client): void
    {
        $this->client = $client;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        if ($this->record instanceof Upstream) {
            $record = UpstreamResource::getEloquentQuery()->whereKey($this->record->id)->firstOrFail();
            abort_unless(UpstreamResource::canEdit($record), 403);
            $snapshot = $this->client->snapshot($record->connector, SourceMetric::cases());
            $mode = $this->sourceView($snapshot, $record->connector);
        } else {
            $record = ReplicaResource::getEloquentQuery()->whereKey($this->record->id)->firstOrFail();
            abort_unless(ReplicaResource::canEdit($record), 403);
            $snapshot = $this->client->snapshot($record->connector, SinkMetric::cases());
            $mode = $this->sinkView($snapshot, $record->connector);
        }

        $sampledAt = $snapshot->oldestSampleAt();

        return [
            'snapshot' => $snapshot,
            'sampledAt' => $sampledAt === null
                ? null
                : CarbonImmutable::createFromTimestamp($sampledAt)->locale(app()->getLocale()),
            'worker' => $this->flag($snapshot->workerUp()),
            ...$mode,
        ];
    }

    /** @return ModeView */
    private function sourceView(MonitoringSnapshot $snapshot, ?Connector $connector): array
    {
        $lastEvent = $snapshot->value(SourceMetric::LastEvent);

        return [
            'stages' => [
                [
                    'title' => __('phpinnacle-ferry::monitoring.source_db'),
                    'icon' => Heroicon::CircleStack,
                    'status' => __('phpinnacle-ferry::monitoring.connection', [
                        'state' => $this->flag($snapshot->value(SourceMetric::Connected)),
                    ]),
                    'value' => $this->number($snapshot->value(SourceMetric::PollRate), '/s'),
                    'label' => $this->fieldLabel('poll_rate'),
                ],
                [
                    'title' => 'Debezium',
                    'icon' => Heroicon::CpuChip,
                    'status' => __('phpinnacle-ferry::monitoring.streaming'),
                    'value' => $this->number($snapshot->value(SourceMetric::SourceLag), 'ms'),
                    'label' => $this->fieldLabel('source_lag'),
                ],
                [
                    'title' => 'Kafka',
                    'icon' => Heroicon::ServerStack,
                    'status' => __('phpinnacle-ferry::monitoring.source_connector'),
                    'value' => $this->number($snapshot->value(SourceMetric::WriteRate), '/s'),
                    'label' => $this->fieldLabel('write_rate'),
                ],
            ],
            ...$this->charts($snapshot, $connector, [
                'ms' => ['source_lag' => SourceMetric::SourceLag],
                '/s' => ['poll_rate' => SourceMetric::PollRate, 'write_rate' => SourceMetric::WriteRate],
            ]),
            'queue' => [
                'text' =>
                    $this->number($snapshot->queueUsed(), precision: 0)
                        . ' / '
                        . $this->number($snapshot->value(SourceMetric::QueueTotalCapacity), precision: 0),
                'percent' => $snapshot->queuePercent(),
            ],
            'summary' => [
                $this->fieldLabel('last_event') => $this->number(
                    $lastEvent === null ? null : $lastEvent / 1000,
                    's',
                ),
                $this->fieldLabel('events') => $this->number($snapshot->value(SourceMetric::Events), precision: 0),
            ],
            'detailsHeading' => __('phpinnacle-ferry::monitoring.snapshot_context'),
            'details' => [
                $this->fieldLabel('snapshot_connected') => $this->flag(
                    $snapshot->value(SourceMetric::SnapshotConnected),
                ),
                $this->fieldLabel('snapshot_running') => $this->flag($snapshot->value(SourceMetric::SnapshotRunning)),
                $this->fieldLabel('snapshot_completed') => $this->flag(
                    $snapshot->value(SourceMetric::SnapshotCompleted),
                ),
                $this->fieldLabel('snapshot_aborted') => $this->flag($snapshot->value(SourceMetric::SnapshotAborted)),
            ],
            'detailsNote' => __('phpinnacle-ferry::monitoring.snapshot_note'),
            'note' => __('phpinnacle-ferry::monitoring.source_note'),
        ];
    }

    /** @return ModeView */
    private function sinkView(MonitoringSnapshot $snapshot, ?Connector $connector): array
    {
        return [
            'stages' => [
                [
                    'title' => 'Kafka',
                    'icon' => Heroicon::ServerStack,
                    'status' => __('phpinnacle-ferry::monitoring.sink_connector'),
                    'value' => $this->number($snapshot->value(SinkMetric::ReadRate), '/s'),
                    'label' => $this->fieldLabel('read_rate'),
                ],
                [
                    'title' => __('phpinnacle-ferry::monitoring.sink_connector'),
                    'icon' => Heroicon::CpuChip,
                    'status' => __('phpinnacle-ferry::monitoring.active_records', [
                        'count' => $this->number($snapshot->value(SinkMetric::Active), precision: 0),
                    ]),
                    'value' => $this->number($snapshot->value(SinkMetric::SendRate), '/s'),
                    'label' => $this->fieldLabel('send_rate'),
                ],
                [
                    'title' => __('phpinnacle-ferry::monitoring.destination_db'),
                    'icon' => Heroicon::CircleStack,
                    'status' => __('phpinnacle-ferry::monitoring.not_measured'),
                    'value' => '—',
                    'label' => __('phpinnacle-ferry::monitoring.committed_writes'),
                ],
            ],
            ...$this->charts($snapshot, $connector, [
                'ms' => ['batch_avg' => SinkMetric::BatchAvg],
                '/s' => ['read_rate' => SinkMetric::ReadRate, 'send_rate' => SinkMetric::SendRate],
            ]),
            'queue' => null,
            'summary' => [
                $this->fieldLabel('active') => $this->number($snapshot->value(SinkMetric::Active), precision: 0),
                $this->fieldLabel('batch_avg') => $this->number($snapshot->value(SinkMetric::BatchAvg), 'ms'),
                $this->fieldLabel('batch_max') => $this->number($snapshot->value(SinkMetric::BatchMax), 'ms'),
            ],
            'detailsHeading' => __('phpinnacle-ferry::monitoring.errors_retries'),
            'details' => [
                $this->fieldLabel('errors') => $this->number($snapshot->value(SinkMetric::Errors), precision: 0),
                $this->fieldLabel('failures') => $this->number($snapshot->value(SinkMetric::Failures), precision: 0),
                $this->fieldLabel('skipped') => $this->number($snapshot->value(SinkMetric::Skipped), precision: 0),
                $this->fieldLabel('retries') => $this->number($snapshot->value(SinkMetric::Retries), precision: 0),
            ],
            'detailsNote' => __('phpinnacle-ferry::monitoring.counter_note'),
            'note' => __('phpinnacle-ferry::monitoring.sink_note'),
        ];
    }

    /**
     * Loads history for exactly the charted metrics, only while current samples are ready.
     *
     * @param non-empty-array<string, non-empty-array<string, SourceMetric>>|non-empty-array<string, non-empty-array<string, SinkMetric>> $charts Metrics by label key, grouped by unit.
     * @return array{charts: list<ChartView>, historyAvailable: bool}
     */
    private function charts(MonitoringSnapshot $snapshot, ?Connector $connector, array $charts): array
    {
        $history = $snapshot->status === MonitoringStatus::Ready
            ? $this->client->history($connector, array_values(array_merge(...array_values($charts))))
            : new MonitoringHistory(available: false);
        $labels = array_map(fn (int $time) => gmdate('H:i:s', $time), array_keys($history->snapshots));
        $views = [];

        foreach ($charts as $unit => $metrics) {
            $datasets = [];
            $current = [];
            $hasData = false;

            foreach ($metrics as $name => $metric) {
                $values = $history->values($metric);
                $hasData = $hasData || array_any($values, fn (?float $value) => $value !== null);
                $datasets[] = [
                    'label' => $this->fieldLabel($name),
                    'data' => $values,
                    ...($datasets === [] ? [] : ['borderDash' => [5, 3], 'borderColor' => 'rgb(99, 102, 241)']),
                ];
                $current[] = $this->number($snapshot->value($metric));
            }

            $views[] = [
                'title' => count($metrics) > 1 ? __('phpinnacle-ferry::monitoring.throughput') : $datasets[0]['label'],
                'summary' => implode(' / ', $current) . ' ' . $unit,
                'unit' => $unit,
                'data' => ['labels' => $labels, 'datasets' => $hasData ? $datasets : []],
            ];
        }

        return ['charts' => $views, 'historyAvailable' => $history->available];
    }

    private function fieldLabel(string $name): string
    {
        return __('phpinnacle-ferry::monitoring.fields.' . $name);
    }

    private function number(?float $value, string $unit = '', int $precision = 2): string
    {
        return $value === null
            ? '—'
            : number_format($value, decimals: $precision, decimal_separator: '.', thousands_separator: ' ')
            . ($unit === '' ? '' : ' ' . $unit);
    }

    private function flag(?float $value): string
    {
        return match ($value) {
            1.0 => __('phpinnacle-ferry::monitoring.yes'),
            0.0 => __('phpinnacle-ferry::monitoring.no'),
            default => '—',
        };
    }
}
