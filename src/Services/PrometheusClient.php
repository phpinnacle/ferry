<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use PHPinnacle\Ferry\Data\MonitoringHistory;
use PHPinnacle\Ferry\Data\MonitoringSample;
use PHPinnacle\Ferry\Data\MonitoringSnapshot;
use PHPinnacle\Ferry\Enums\MonitoringStatus;
use PHPinnacle\Ferry\Enums\SinkMetric;
use PHPinnacle\Ferry\Enums\SourceMetric;
use PHPinnacle\Ferry\Models\Connector;
use UnexpectedValueException;

/**
 * @phpstan-type OriginPrometheusConfig array{base_uri: ?string, labels: array<string, string>, headers: array<string, string>, stale_after: int}
 */
class PrometheusClient
{
    private const int HISTORY_WINDOW = 900;
    private const int HISTORY_STEP = 30;

    /** @param array<string, array{prometheus?: OriginPrometheusConfig, ...}> $origins */
    public function __construct(
        private readonly array $origins,
        private readonly int $timeout,
    ) {}

    public function isEnabled(string $origin): bool
    {
        return $this->configuration($origin) !== null;
    }

    /** @param non-empty-list<SourceMetric>|non-empty-list<SinkMetric> $metrics */
    public function snapshot(?Connector $connector, array $metrics): MonitoringSnapshot
    {
        if ($connector === null) {
            return new MonitoringSnapshot(MonitoringStatus::NoConnector);
        }

        $config = $this->configuration($connector->origin);

        if ($config === null) {
            return new MonitoringSnapshot(MonitoringStatus::Disabled);
        }

        [$query, $timestampQuery] = $this->queries($this->selectors($connector, $config['labels'], $metrics));
        $now = now()->getTimestamp();
        $http = $this->http($config);

        try {
            return $this->snapshots(
                $this->query($http, $query, $now),
                $this->query($http, $timestampQuery, $now),
                $now,
                $now,
                $config['stale_after'],
            )[$now];
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException) {
            return new MonitoringSnapshot(MonitoringStatus::Unavailable);
        }
    }

    /** @param non-empty-list<SourceMetric>|non-empty-list<SinkMetric> $metrics */
    public function history(?Connector $connector, array $metrics): MonitoringHistory
    {
        if ($connector === null || ($config = $this->configuration($connector->origin)) === null) {
            return new MonitoringHistory(available: false);
        }

        [$query, $timestampQuery] = $this->queries($this->selectors($connector, $config['labels'], $metrics));
        $end = now()->getTimestamp();
        $start = $end - self::HISTORY_WINDOW;
        $http = $this->http($config);

        try {
            return new MonitoringHistory($this->snapshots(
                $this->rangeQuery($http, $query, $start, $end),
                $this->rangeQuery($http, $timestampQuery, $start, $end),
                $start,
                $end,
                $config['stale_after'],
            ));
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException) {
            return new MonitoringHistory(available: false);
        }
    }

    /** @return (OriginPrometheusConfig&array{base_uri: string})|null */
    private function configuration(string $origin): ?array
    {
        $config = $this->origins[$origin]['prometheus'] ?? null;

        return $config === null || $config['base_uri'] === null || $config['base_uri'] === '' ? null : $config;
    }

    /**
     * Selects the worker scrape series plus Connect metrics by connector name and Debezium metrics by applied server.
     *
     * @param array<string, string> $labels
     * @param list<SourceMetric>|list<SinkMetric> $metrics
     * @return array<string, string>
     */
    private function selectors(Connector $connector, array $labels, array $metrics): array
    {
        $connect = [];
        $debezium = [];
        $contexts = [];

        foreach ($metrics as $metric) {
            if ($metric->context() === null) {
                $connect[] = $metric->prometheusName();
            } else {
                $debezium[] = $metric->prometheusName();
                $contexts[] = $metric->context();
            }
        }

        $selectors =
            $this->metricSelectors([MonitoringSnapshot::WORKER_METRIC], $labels)
            + $this->metricSelectors($connect, $labels, ['connector' => $connector->name]);
        /** @var string|null $server */
        $server = $connector->applied_config['topic.prefix'] ?? null;

        if ($debezium !== [] && $server !== null) {
            $contexts = array_values(array_unique($contexts));
            $context = count($contexts) === 1
                ? 'context=' . $this->quote($contexts[0])
                : 'context=~' . $this->quote(implode('|', $contexts));
            $selectors += $this->metricSelectors($debezium, $labels, ['server' => $server], $context);
        }

        return $selectors;
    }

    /**
     * Pairs each evaluated value with its original scrape timestamp and derives one snapshot per step.
     *
     * @param array<string, array{name: string, context: ?string, values: array<int, float|null>}> $values
     * @param array<string, array{name: string, context: ?string, values: array<int, float|null>}> $timestamps
     * @return array<int, MonitoringSnapshot>
     */
    private function snapshots(array $values, array $timestamps, int $start, int $end, int $staleAfter): array
    {
        $snapshots = [];

        for ($time = $start; $time <= $end; $time += self::HISTORY_STEP) {
            $samples = [];

            foreach ($values as $key => $series) {
                if (!array_key_exists($time, $series['values'])) {
                    continue;
                }

                $sampledAt = $timestamps[$key]['values'][$time] ?? null;

                if ($sampledAt === null || $sampledAt < 0 || $sampledAt > $time) {
                    throw new UnexpectedValueException('Missing or invalid Prometheus sample timestamp.');
                }

                $samples[] = new MonitoringSample(
                    $series['name'],
                    $series['context'],
                    $series['values'][$time],
                    $sampledAt,
                );
            }

            $snapshots[$time] = MonitoringSnapshot::fromSamples($samples, $time, $staleAfter);
        }

        return $snapshots;
    }

    /** @param OriginPrometheusConfig&array{base_uri: string} $config */
    private function http(array $config): PendingRequest
    {
        return Http::baseUrl($config['base_uri'])
            ->acceptJson()
            ->asForm()
            ->withHeaders($config['headers'])
            ->connectTimeout($this->timeout)
            ->timeout($this->timeout);
    }

    /** @param array<string, string> $selectors
     *  @return array{string, string}
     */
    private function queries(array $selectors): array
    {
        $values = [];
        $timestamps = [];

        foreach ($selectors as $name => $selector) {
            $metric = $this->quote($name);
            // Label each metric before union so series with otherwise identical labels remain distinct.
            $values[] = 'label_replace(' . $selector . ', "ferry_metric", ' . $metric . ', "", "")';
            // timestamp() must receive raw selectors to retain scrape time rather than expression evaluation time.
            $timestamps[] = 'label_replace(timestamp(' . $selector . '), "ferry_metric", ' . $metric . ', "", "")';
        }

        return [implode(' or ', $values), implode(' or ', $timestamps)];
    }

    /**
     * @param list<string> $names
     * @param array<string, string> $labels
     * @param array<string, string> $identity
     * @return array<string, string>
     */
    private function metricSelectors(array $names, array $labels, array $identity = [], ?string $context = null): array
    {
        $matchers = [];

        foreach ([$labels, $identity] as $filters) {
            foreach ($filters as $name => $value) {
                $matchers[] = $name . '=' . $this->quote($value);
            }
        }

        if ($context !== null) {
            $matchers[] = $context;
        }

        $selectors = [];

        foreach ($names as $name) {
            $selectors[$name] = '{' . implode(',', ['__name__=' . $this->quote($name), ...$matchers]) . '}';
        }

        return $selectors;
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, int|string> $payload
     * @return list<mixed>
     */
    private function post(PendingRequest $http, string $endpoint, array $payload, string $resultType): array
    {
        $data = json_decode(
            $http->post($endpoint, $payload)->throw()->body(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            !is_array($data)
            || ($data['status'] ?? null) !== 'success'
            || !is_array($data['data'] ?? null)
            || ($data['data']['resultType'] ?? null) !== $resultType
            || !is_array($data['data']['result'] ?? null)
            || !array_is_list($data['data']['result'])
        ) {
            throw new UnexpectedValueException('Expected a successful Prometheus ' . $resultType . ' response.');
        }

        return $data['data']['result'];
    }

    /** @return array<string, array{name: string, context: ?string, values: array<int, float|null>}> */
    private function query(PendingRequest $http, string $query, int $time): array
    {
        $result = [];

        foreach ($this->post($http, 'api/v1/query', ['query' => $query, 'time' => $time], 'vector') as $item) {
            if (!is_array($item) || !is_array($item['value'] ?? null)) {
                throw new UnexpectedValueException('Invalid Prometheus vector sample.');
            }

            $series = $this->series($item['metric'] ?? null);

            if (
                count($item['value']) !== 2
                || !is_numeric($item['value'][0] ?? null)
                || !is_string($item['value'][1] ?? null)
            ) {
                throw new UnexpectedValueException('Invalid Prometheus sample value.');
            }

            $result[$series['key']] = [
                'name' => $series['name'],
                'context' => $series['context'],
                'values' => [$time => $this->number($item['value'][1])],
            ];
        }

        return $result;
    }

    /** @return array<string, array{name: string, context: ?string, values: array<int, float|null>}> */
    private function rangeQuery(PendingRequest $http, string $query, int $start, int $end): array
    {
        $result = [];
        $items = $this->post(
            $http,
            'api/v1/query_range',
            ['query' => $query, 'start' => $start, 'end' => $end, 'step' => self::HISTORY_STEP],
            'matrix',
        );

        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['values'] ?? null) || !array_is_list($item['values'])) {
                throw new UnexpectedValueException('Invalid Prometheus matrix series.');
            }

            $series = $this->series($item['metric'] ?? null);
            $values = [];

            foreach ($item['values'] as $sample) {
                if (
                    !is_array($sample)
                    || count($sample) !== 2
                    || !is_numeric($sample[0] ?? null)
                    || !is_finite((float) $sample[0])
                    || !is_string($sample[1] ?? null)
                    || (float) $sample[0] !== (float) (int) $sample[0]
                ) {
                    throw new UnexpectedValueException('Invalid historical Prometheus sample.');
                }

                $timestamp = (int) $sample[0];

                if (
                    $timestamp < $start
                    || $timestamp > $end
                    || (($timestamp - $start) % self::HISTORY_STEP) !== 0
                ) {
                    throw new UnexpectedValueException('Invalid historical Prometheus sample.');
                }

                $values[$timestamp] = $this->number($sample[1]);
            }

            $result[$series['key']] = [
                'name' => $series['name'],
                'context' => $series['context'],
                'values' => $values,
            ];
        }

        return $result;
    }

    /** @return array{key: string, name: string, context: ?string} */
    private function series(mixed $metric): array
    {
        if (!is_array($metric) || !is_string($metric['ferry_metric'] ?? null)) {
            throw new UnexpectedValueException('Missing Prometheus metric identity.');
        }

        $labels = [];

        foreach ($metric as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new UnexpectedValueException('Invalid Prometheus label value.');
            }

            if ($name !== '__name__') {
                $labels[$name] = $value;
            }
        }

        ksort($labels);

        return [
            'key' => json_encode($labels, JSON_THROW_ON_ERROR),
            'name' => $metric['ferry_metric'],
            'context' => $labels['context'] ?? null,
        ];
    }

    private function number(string $raw): ?float
    {
        if (!is_numeric($raw) && !in_array($raw, ['NaN', '+Inf', '-Inf'], true)) {
            throw new UnexpectedValueException('Invalid Prometheus numeric value.');
        }

        return is_numeric($raw) && is_finite((float) $raw) ? (float) $raw : null;
    }
}
