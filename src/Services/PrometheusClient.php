<?php

namespace PHPinnacle\Ferry\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
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
    /** @param array<string, array{prometheus?: OriginPrometheusConfig, ...}> $origins */
    public function __construct(
        private readonly array $origins,
        private readonly int $timeout,
    ) {}

    public function source(?Connector $connector): MonitoringSnapshot
    {
        return $this->read($connector, function (Connector $connector, array $labels) {
            $selectors = $this->selectors(SourceMetric::connectNames(), $labels, [
                'connector' => $connector->name,
            ]);

            if (($connector->applied_config['topic.prefix'] ?? null) !== null) {
                /** @var string $server */
                $server = $connector->applied_config['topic.prefix'];
                $selectors += $this->selectors(
                    SourceMetric::debeziumNames(),
                    $labels,
                    ['server' => $server],
                    'context=~"streaming|snapshot"',
                );
            }

            return $selectors;
        });
    }

    public function sink(?Connector $connector): MonitoringSnapshot
    {
        return $this->read(
            $connector,
            fn (Connector $connector, array $labels) => $this->selectors(
                SinkMetric::connectNames(),
                $labels,
                ['connector' => $connector->name],
            ),
        );
    }

    /** @param Closure(Connector, array<string, string>): array<string, string> $connectorSelectors */
    private function read(?Connector $connector, Closure $connectorSelectors): MonitoringSnapshot
    {
        if ($connector === null) {
            return new MonitoringSnapshot(MonitoringStatus::NoConnector);
        }

        $config = $this->origins[$connector->origin]['prometheus'] ?? null;

        if ($config === null || $config['base_uri'] === null || $config['base_uri'] === '') {
            return new MonitoringSnapshot(MonitoringStatus::Disabled);
        }

        $selectors =
            $this->selectors([MonitoringSnapshot::WORKER_METRIC], $config['labels'])
            + $connectorSelectors($connector, $config['labels']);

        $query = 'label_replace((' . implode(' or ', $selectors) . '), "ferry_metric", "$1", "__name__", "(.+)")';
        // timestamp() must receive raw selectors to retain scrape time rather than expression evaluation time.
        $timestampQuery = implode(' or ', array_map(
            fn (string $name, string $selector) => (
                'label_replace(timestamp(' . $selector . '), "ferry_metric", ' . $this->quote($name) . ', "", "")'
            ),
            array_keys($selectors),
            array_values($selectors),
        ));
        $now = now()->getTimestamp();
        $http = Http::baseUrl($config['base_uri'])
            ->acceptJson()
            ->asForm()
            ->withHeaders($config['headers'])
            ->connectTimeout($this->timeout)
            ->timeout($this->timeout);

        try {
            $values = $this->query($http, $query, $now);
            $timestamps = $this->query($http, $timestampQuery, $now);
            $samples = [];

            foreach ($values as $key => $value) {
                $sampledAt = $timestamps[$key]['value'] ?? null;

                if ($sampledAt === null || $sampledAt < 0 || $sampledAt > $now) {
                    throw new UnexpectedValueException('Missing or invalid Prometheus sample timestamp.');
                }

                $samples[] = new MonitoringSample($value['name'], $value['context'], $value['value'], $sampledAt);
            }

            return MonitoringSnapshot::fromSamples($samples, $now, $config['stale_after']);
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException) {
            return new MonitoringSnapshot(MonitoringStatus::Unavailable);
        }
    }

    /**
     * @param list<string> $names
     * @param array<string, string> $labels
     * @param array<string, string> $identity
     * @return array<string, string>
     */
    private function selectors(array $names, array $labels, array $identity = [], ?string $context = null): array
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

    /** @return array<string, array{name: string, context: ?string, value: ?float}> */
    private function query(PendingRequest $http, string $query, int $time): array
    {
        $data = json_decode(
            $http->post('api/v1/query', ['query' => $query, 'time' => $time])->throw()->body(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            !is_array($data)
            || ($data['status'] ?? null) !== 'success'
            || !is_array($data['data'] ?? null)
            || ($data['data']['resultType'] ?? null) !== 'vector'
            || !is_array($data['data']['result'] ?? null)
            || !array_is_list($data['data']['result'])
        ) {
            throw new UnexpectedValueException('Expected a successful Prometheus vector response.');
        }

        $result = [];

        foreach ($data['data']['result'] as $item) {
            if (!is_array($item) || !is_array($item['metric'] ?? null) || !is_array($item['value'] ?? null)) {
                throw new UnexpectedValueException('Invalid Prometheus vector sample.');
            }

            $labels = $item['metric'];

            foreach ($labels as $label) {
                if (!is_string($label)) {
                    throw new UnexpectedValueException('Invalid Prometheus label value.');
                }
            }

            if (
                ($labels['ferry_metric'] ?? null) === null
                || count($item['value']) !== 2
                || !is_numeric($item['value'][0] ?? null)
                || !is_string($item['value'][1] ?? null)
            ) {
                throw new UnexpectedValueException('Invalid Prometheus sample value.');
            }

            $raw = $item['value'][1];

            if (!is_numeric($raw) && !in_array($raw, ['NaN', '+Inf', '-Inf'], true)) {
                throw new UnexpectedValueException('Invalid Prometheus numeric value.');
            }

            $value = is_numeric($raw) && is_finite((float) $raw) ? (float) $raw : null;
            unset($labels['__name__']);
            ksort($labels);
            $result[json_encode($labels, JSON_THROW_ON_ERROR)] = [
                'name' => $labels['ferry_metric'],
                'context' => $labels['context'] ?? null,
                'value' => $value,
            ];
        }

        return $result;
    }
}
