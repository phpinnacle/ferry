<?php

namespace PHPinnacle\Ferry\Enums;

enum SinkMetric
{
    case ReadRate;
    case SendRate;
    case Active;
    case BatchAvg;
    case BatchMax;
    case Errors;
    case Failures;
    case Skipped;
    case Retries;

    private const string CONNECT = 'kafka_connect_sink_task_metrics_';
    private const string ERRORS = 'kafka_connect_task_error_metrics_';

    public function prometheusName(): string
    {
        return match ($this) {
            self::ReadRate => self::CONNECT . 'sink_record_read_rate',
            self::SendRate => self::CONNECT . 'sink_record_send_rate',
            self::Active => self::CONNECT . 'sink_record_active_count',
            self::BatchAvg => self::CONNECT . 'put_batch_avg_time_ms',
            self::BatchMax => self::CONNECT . 'put_batch_max_time_ms',
            self::Errors => self::ERRORS . 'total_record_errors',
            self::Failures => self::ERRORS . 'total_record_failures',
            self::Skipped => self::ERRORS . 'total_records_skipped',
            self::Retries => self::ERRORS . 'total_retries',
        };
    }

    public function context(): null
    {
        return null;
    }

    /** @param non-empty-list<float> $values */
    public function aggregate(array $values): float
    {
        return match ($this) {
            self::BatchMax => max($values),
            self::BatchAvg => array_sum($values) / count($values),
            default => array_sum($values),
        };
    }
}
