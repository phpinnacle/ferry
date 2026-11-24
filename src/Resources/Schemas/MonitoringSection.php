<?php

namespace PHPinnacle\Ferry\Resources\Schemas;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use PHPinnacle\Ferry\Data\MonitoringSnapshot;
use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\PrometheusClient;

class MonitoringSection
{
    public static function source(): Section
    {
        $snapshot = null;

        return self::section(
            __('phpinnacle-ferry::monitoring.source_description'),
            function (PrometheusClient $client, ?Upstream $record) use (&$snapshot) {
                $snapshot ??= $client->source($record?->connector);

                return [
                    ...self::summaryEntries($snapshot),
                    ...self::sourceEntries($snapshot),
                ];
            },
        )
            ->hidden(
                fn (PrometheusClient $client, ?Upstream $record) => (
                    $record === null
                    || $client->configuration($record->origin) === null
                ),
            );
    }

    public static function sink(): Section
    {
        $snapshot = null;

        return self::section(
            __('phpinnacle-ferry::monitoring.sink_description'),
            function (PrometheusClient $client, ?Replica $record) use (&$snapshot) {
                $snapshot ??= $client->sink($record?->connector);

                return [
                    ...self::summaryEntries($snapshot),
                    ...self::sinkEntries($snapshot),
                ];
            },
        )
            ->hidden(
                fn (PrometheusClient $client, ?Replica $record) => (
                    $record === null
                    || $client->configuration($record->upstream->origin) === null
                ),
            );
    }

    private static function section(string $description, Closure $schema): Section
    {
        return Section::make(__('phpinnacle-ferry::monitoring.title'))
            ->compact()
            ->visibleOn('edit')
            ->columns(['sm' => 2, 'lg' => 4])
            ->extraAttributes(['wire:poll.30s' => '$refresh'])
            ->description($description)
            ->schema($schema);
    }

    /** @return list<TextEntry> */
    private static function summaryEntries(MonitoringSnapshot $snapshot): array
    {
        $sampledAt = $snapshot->oldestSampleAt();

        return [
            self::entry('status', $snapshot->status)->badge(),
            self::entry(
                'sampled_at',
                $sampledAt === null ? '—' : CarbonImmutable::createFromTimestamp($sampledAt)->toIso8601String(),
            ),
            self::entry('worker', self::flag($snapshot->workerUp())),
        ];
    }

    /** @return list<TextEntry> */
    private static function sourceEntries(MonitoringSnapshot $snapshot): array
    {
        $total = $snapshot->value(SourceMetric::QueueTotalCapacity);
        $remaining = $snapshot->value(SourceMetric::QueueRemainingCapacity);
        $queue =
            $total === null || $remaining === null
                ? '—'
                : self::number($total - $remaining) . ' / ' . self::number($total);

        return [
            self::entry('connected', self::flag($snapshot->value(SourceMetric::Connected))),
            self::entry('source_lag', self::number($snapshot->value(SourceMetric::SourceLag), 'ms')),
            self::entry('last_event', self::number($snapshot->value(SourceMetric::LastEvent), 'ms')),
            self::entry('events', self::number($snapshot->value(SourceMetric::Events))),
            self::entry('queue', $queue),
            self::entry('poll_rate', self::number($snapshot->value(SourceMetric::PollRate), '/s')),
            self::entry('write_rate', self::number($snapshot->value(SourceMetric::WriteRate), '/s')),
            self::entry('snapshot_connected', self::flag($snapshot->value(SourceMetric::SnapshotConnected))),
            self::entry('snapshot_running', self::flag($snapshot->value(SourceMetric::SnapshotRunning))),
            self::entry('snapshot_completed', self::flag($snapshot->value(SourceMetric::SnapshotCompleted))),
            self::entry('snapshot_aborted', self::flag($snapshot->value(SourceMetric::SnapshotAborted))),
        ];
    }

    /** @return list<TextEntry> */
    private static function sinkEntries(MonitoringSnapshot $snapshot): array
    {
        return [
            self::entry('read_rate', self::number($snapshot->value(SinkMetric::ReadRate), '/s')),
            self::entry('send_rate', self::number($snapshot->value(SinkMetric::SendRate), '/s')),
            self::entry('active', self::number($snapshot->value(SinkMetric::Active))),
            self::entry('batch_avg', self::number($snapshot->value(SinkMetric::BatchAvg), 'ms')),
            self::entry('batch_max', self::number($snapshot->value(SinkMetric::BatchMax), 'ms')),
            self::entry('errors', self::number($snapshot->value(SinkMetric::Errors))),
            self::entry('failures', self::number($snapshot->value(SinkMetric::Failures))),
            self::entry('skipped', self::number($snapshot->value(SinkMetric::Skipped))),
            self::entry('retries', self::number($snapshot->value(SinkMetric::Retries))),
        ];
    }

    private static function entry(string $name, string|MonitoringStatus $value): TextEntry
    {
        return TextEntry::make('monitoring_' . $name)
            ->label(__('phpinnacle-ferry::monitoring.fields.' . $name))
            ->state($value);
    }

    private static function number(?float $value, string $unit = ''): string
    {
        return $value === null
            ? '—'
            : number_format($value, decimals: 2, decimal_separator: '.', thousands_separator: ' ')
            . ($unit === '' ? '' : ' ' . $unit);
    }

    private static function flag(?float $value): string
    {
        return match ($value) {
            1.0 => __('phpinnacle-ferry::monitoring.yes'),
            0.0 => __('phpinnacle-ferry::monitoring.no'),
            default => '—',
        };
    }
}
