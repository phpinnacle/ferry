<?php

namespace PHPinnacle\Ferry\Enums;

enum SourceMetric
{
    case Connected;
    case SourceLag;
    case LastEvent;
    case Events;
    case QueueRemainingCapacity;
    case QueueTotalCapacity;
    case SnapshotConnected;
    case SnapshotRunning;
    case SnapshotCompleted;
    case SnapshotAborted;
    case PollRate;
    case WriteRate;

    private const string DEBEZIUM = 'debezium_postgres_connector_metrics_';
    private const string CONNECT = 'kafka_connect_source_task_metrics_';

    /** @return list<string> */
    public static function connectNames(): array
    {
        return self::names(debezium: false);
    }

    /** @return list<string> */
    public static function debeziumNames(): array
    {
        return self::names(debezium: true);
    }

    public function prometheusName(): string
    {
        return match ($this) {
            self::Connected, self::SnapshotConnected => self::DEBEZIUM . 'connected',
            self::SourceLag => self::DEBEZIUM . 'millisecondsbehindsource',
            self::LastEvent => self::DEBEZIUM . 'millisecondssincelastevent',
            self::Events => self::DEBEZIUM . 'totalnumberofeventsseen',
            self::QueueRemainingCapacity => self::DEBEZIUM . 'queueremainingcapacity',
            self::QueueTotalCapacity => self::DEBEZIUM . 'queuetotalcapacity',
            self::SnapshotRunning => self::DEBEZIUM . 'snapshotrunning',
            self::SnapshotCompleted => self::DEBEZIUM . 'snapshotcompleted',
            self::SnapshotAborted => self::DEBEZIUM . 'snapshotaborted',
            self::PollRate => self::CONNECT . 'source_record_poll_rate',
            self::WriteRate => self::CONNECT . 'source_record_write_rate',
        };
    }

    public function context(): ?string
    {
        return match ($this) {
            self::Connected,
            self::SourceLag,
            self::LastEvent,
            self::Events,
            self::QueueRemainingCapacity,
            self::QueueTotalCapacity,
                => 'streaming',
            self::SnapshotConnected,
            self::SnapshotRunning,
            self::SnapshotCompleted,
            self::SnapshotAborted,
                => 'snapshot',
            self::PollRate, self::WriteRate => null,
        };
    }

    /** @param non-empty-list<float> $values */
    public function aggregate(array $values): float
    {
        return match ($this) {
            self::Connected, self::SnapshotConnected, self::SnapshotCompleted => min($values),
            self::SourceLag, self::LastEvent, self::SnapshotRunning, self::SnapshotAborted => max($values),
            default => array_sum($values),
        };
    }

    /** @return list<string> */
    private static function names(bool $debezium): array
    {
        $names = [];

        foreach (self::cases() as $metric) {
            if (($metric->context() !== null) === $debezium) {
                $names[] = $metric->prometheusName();
            }
        }

        return array_values(array_unique($names));
    }
}
