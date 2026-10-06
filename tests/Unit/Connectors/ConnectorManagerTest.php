<?php

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Contracts\SignalProducer;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\Connectors\SinkConnectorConfigBuilder;
use PHPinnacle\Ferry\Services\Connectors\SnapshotSignaler;
use PHPinnacle\Ferry\Services\Connectors\SourceConnectorConfigBuilder;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Franz\Client;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'database.connections.sqlite.host' => 'db.internal',
        'database.connections.sqlite.port' => 5432,
    ]);
});

function ferry_sync(): PHPinnacle\Ferry\Models\Sync
{
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    return TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
    ], ['status' => SyncStatus::Active]);
}

function ferry_connector_manager(
    RecordingConnectClient $http,
    ?RecordingSignalProducer $signals = null,
): ConnectorManager {
    $factory = new HttpFactory;
    $client = new Client('http://connect.example:8083', $http, $factory, $factory);
    $source = new SourceConnectorConfigBuilder;
    $destinations = new SyncDestinationResolver(new StaticDestinationRegistry);

    return new ConnectorManager(
        $client,
        $source,
        new SinkConnectorConfigBuilder($source, $destinations),
        new SnapshotSignaler($signals ?? new RecordingSignalProducer, $source),
        new SyncReviewer($destinations),
    );
}

function missing_connector_response(): Response
{
    return new Response(404, [], '{"error_code":404,"message":"Connector not found"}');
}

function connector_response(): Response
{
    return new Response(200, [], json_encode(['name' => 'x', 'config' => [], 'tasks' => []], JSON_THROW_ON_ERROR));
}

/** @param array<string, string> $config */
function config_response(array $config): Response
{
    return new Response(200, [], json_encode($config, JSON_THROW_ON_ERROR));
}

function status_response(string $state = 'RUNNING'): Response
{
    return new Response(200, [], json_encode([
        'name' => 'x',
        'connector' => ['state' => $state, 'worker_id' => 'w:8083'],
        'tasks' => [],
    ], JSON_THROW_ON_ERROR));
}

/** @return array<string, string> */
function ferry_sent_config(RecordingConnectClient $http, int $index): array
{
    /** @var array<string, string> */
    return json_decode((string) $http->requests[$index]->getBody(), true, flags: JSON_THROW_ON_ERROR);
}

it('rejects unsupported drivers with a translated error on activate', function () {
    $connection = TestCase::makeConnection([
        'driver' => \PHPinnacle\Ferry\Enums\Driver::Sqlsrv,
    ], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $sync = TestCase::makeSync(['connection_id' => $connection->id]);
    $http = new RecordingConnectClient;

    expect(fn () => ferry_connector_manager($http)->activate($sync))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.connection.errors.unsupported_driver', [
            'driver' => $connection->driver->getLabel(),
        ]));
});

it('rejects unsupported drivers with a translated error on refreshSource', function () {
    $connection = TestCase::makeConnection([
        'driver' => \PHPinnacle\Ferry\Enums\Driver::Sqlsrv,
    ], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $http = new RecordingConnectClient;

    expect(fn () => ferry_connector_manager($http)->refreshSource($connection))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.connection.errors.unsupported_driver', [
            'driver' => $connection->driver->getLabel(),
        ]));
});

it('rejects unsupported drivers with a translated error on pause', function () {
    $connection = TestCase::makeConnection([
        'driver' => \PHPinnacle\Ferry\Enums\Driver::Sqlsrv,
    ], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $sync = TestCase::makeSync(['connection_id' => $connection->id]);
    $http = new RecordingConnectClient;

    expect(fn () => ferry_connector_manager($http)->pause($sync))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.connection.errors.unsupported_driver', [
            'driver' => $connection->driver->getLabel(),
        ]));
});

it('rejects unsupported drivers with a translated error on restart', function () {
    $connection = TestCase::makeConnection([
        'driver' => \PHPinnacle\Ferry\Enums\Driver::Sqlsrv,
    ], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $sync = TestCase::makeSync(['connection_id' => $connection->id]);
    $http = new RecordingConnectClient;

    expect(fn () => ferry_connector_manager($http)->restart($sync))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.connection.errors.unsupported_driver', [
            'driver' => $connection->driver->getLabel(),
        ]));
});

it('refuses to activate a synchronization whose mapping no longer matches the source', function () {
    $sync = ferry_sync();
    $sync->forceFill([
        'schema' => [new FieldMapping('_missing', 'external_id', new ScalarField(FieldType::Id))],
    ])->saveQuietly();
    $http = new RecordingConnectClient;

    expect(fn () => ferry_connector_manager($http)->activate($sync->fresh()))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.sync.errors.invalid_mapping', [
            'code' => $sync->code,
        ]))
        ->and($http->routes())
        ->toBe([]);
});

it('activates a synchronization by pushing the connector configs and resuming both', function () {
    $sync = ferry_sync();
    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(
        missing_connector_response(),
        connector_response(),
        connector_response(),
        new Response(202),
        new Response(202),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http, $signals)->activate($sync);

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Active)
        ->and($sync->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Running)
        ->and($sync->fresh()->connector?->config)
        ->toBe(ferry_sent_config($http, 2))
        ->and($sync->fresh()->connection->connector?->config)
        ->toBe(ferry_sent_config($http, 1))
        ->and($sync->fresh()->connector?->checked_at)
        ->not->toBeNull()->and($sync->fresh()->connection->connector?->checked_at)
        ->not->toBeNull()->and(Connector::query()->count())->toBe(2)->and($http->routes())->toBe([
            ['GET', '/connectors/ferry-source-test-connection/config'],
            ['PUT', '/connectors/ferry-source-test-connection/config'],
            ['PUT', '/connectors/ferry-sink-test-sync/config'],
            ['PUT', '/connectors/ferry-source-test-connection/resume'],
            ['PUT', '/connectors/ferry-sink-test-sync/resume'],
            ['GET', '/connectors/ferry-source-test-connection/status'],
            ['GET', '/connectors/ferry-sink-test-sync/status'],
        ])->and($signals->published)->toBe([]);
});

it('scopes the shared source connector to the synchronization being activated', function () {
    $sync = ferry_sync();
    $sync->pause();

    $http = new RecordingConnectClient(
        missing_connector_response(),
        connector_response(),
        connector_response(),
        new Response(202),
        new Response(202),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http)->activate($sync);

    expect(ferry_sent_config($http, 1)['table.include.list'])->toBe('public._reference0');
});

it('does not request an incremental snapshot for the very first synchronization of a connection', function () {
    $sync = ferry_sync();
    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(
        missing_connector_response(),
        connector_response(),
        connector_response(),
        new Response(202),
        new Response(202),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http, $signals)->activate($sync);

    expect($signals->published)->toBe([]);
});

it('does not request an incremental snapshot when re-activating without new tables or columns', function () {
    $sync = ferry_sync();
    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(
        config_response(['column.include.list' => 'public._reference0._idrref']),
        connector_response(),
        connector_response(),
        new Response(202),
        new Response(202),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http, $signals)->activate($sync);

    expect($signals->published)->toBe([]);
});

it('requests an incremental snapshot when a new synchronization extends the shared source connector', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-1',
        'name' => '_reference1',
        'code' => 2,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 1',
        'title' => 'Object 1',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 1,
        'revision' => $connection->generation,
    ]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-active',
        'source' => 'object-0',
    ], ['status' => SyncStatus::Active]);

    $newSync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-new',
        'source' => 'object-1',
    ]);

    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(
        config_response(['column.include.list' => 'public._reference0._idrref']),
        connector_response(),
        connector_response(),
        new Response(202),
        new Response(202),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http, $signals)->activate($newSync);

    expect($signals->published)->toHaveCount(1);

    $signal = $signals->published[0];

    expect($signal['topic'])
        ->toBe('ferry-signal')
        ->and($signal['key'])
        ->toBe('ferry.test-connection')
        ->and(json_decode($signal['payload'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'type' => 'execute-snapshot',
            'data' => [
                'data-collections' => ['public._reference1'],
                'type' => 'INCREMENTAL',
            ],
        ]);
});

it('keeps capturing the table of a paused synchronization on the shared source connector', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(
        new Response(202),
        connector_response(),
        status_response('PAUSED'),
    );

    ferry_connector_manager($http)->pause($sync);

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and($sync->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Paused)
        ->and(ferry_sent_config($http, 1)['table.include.list'])
        ->toBe('public._reference0')
        ->and($http->routes())
        ->toBe([
            ['PUT', '/connectors/ferry-sink-test-sync/pause'],
            ['PUT', '/connectors/ferry-source-test-connection/config'],
            ['GET', '/connectors/ferry-sink-test-sync/status'],
        ]);
});

it('keeps capturing a paused synchronization table while a sibling synchronization stays active', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-0',
        'name' => '_reference0',
        'code' => 1,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 0',
        'title' => 'Object 0',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 0,
        'revision' => $connection->generation,
    ]);

    ConnectionMetadata::create([
        'connection_id' => $connection->id,
        'external_id' => 'object-1',
        'name' => '_reference1',
        'code' => 2,
        'kind' => MetadataKind::Reference,
        'label' => 'Object 1',
        'title' => 'Object 1',
        'system' => ['_idrref' => new ScalarField(FieldType::Id)],
        'properties' => [],
        'values' => [],
        'position' => 1,
        'revision' => $connection->generation,
    ]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-active',
        'source' => 'object-0',
    ], ['status' => SyncStatus::Active]);

    $paused = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-paused',
        'source' => 'object-1',
    ], ['status' => SyncStatus::Active]);

    $http = new RecordingConnectClient(
        new Response(202),
        connector_response(),
        status_response('PAUSED'),
    );

    ferry_connector_manager($http)->pause($paused);

    expect(explode(',', ferry_sent_config($http, 1)['table.include.list']))
        ->toContain('public._reference0', 'public._reference1');
});

it('never lets an unscoped source connector capture the whole database', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = new SourceConnectorConfigBuilder()->build($connection, $connection->trackedSyncs());

    expect($config)
        ->not->toHaveKey('table.include.list')->and($config)
        ->not->toHaveKey('message.key.columns');
});

it('restarts the failed tasks of both connectors', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(
        new Response(204),
        new Response(204),
        status_response(),
        status_response(),
    );

    ferry_connector_manager($http)->restart($sync);

    expect($http->routes())
        ->toBe([
            ['POST', '/connectors/ferry-source-test-connection/restart'],
            ['POST', '/connectors/ferry-sink-test-sync/restart'],
            ['GET',  '/connectors/ferry-source-test-connection/status'],
            ['GET',  '/connectors/ferry-sink-test-sync/status'],
        ]);
});

it('pushes the source connector config again when the connection credentials change', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(connector_response(), status_response());

    ferry_connector_manager($http)->refreshSource($sync->connection);

    expect($sync->connection->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Running)
        ->and($sync->connection->fresh()->connector?->config)
        ->toBe(ferry_sent_config($http, 0))
        ->and(ferry_sent_config($http, 0)['table.include.list'])
        ->toBe('public._reference0')
        ->and($http->routes())
        ->toBe([
            ['PUT', '/connectors/ferry-source-test-connection/config'],
            ['GET', '/connectors/ferry-source-test-connection/status'],
        ]);
});

it('keeps the last applied configuration when Kafka Connect rejects an update', function (string $failedRole) {
    $sync = ferry_sync();
    $connection = $sync->connection;
    $previousSource = ['column.include.list' => 'public._reference0._idrref'];
    $previousSink = ['connection.password' => 'old-password'];
    $source = $connection
        ->connector()
        ->create([
            'name' => 'ferry-source-' . $connection->code,
            'config' => $previousSource,
        ]);
    $sink = $sync->connector()->create([
        'name' => 'ferry-sink-' . $sync->code,
        'config' => $previousSink,
    ]);
    $rejected = new Response(500, [], '{"error_code":500,"message":"Rejected configuration"}');
    $http = new RecordingConnectClient(
        config_response($previousSource),
        ...$failedRole === 'source' ? [$rejected] : [connector_response(), $rejected],
    );

    expect(fn () => ferry_connector_manager($http)->activate($sync))
        ->toThrow(ApiException::class)
        ->and($source->fresh()->config)
        ->toBe($failedRole === 'source' ? $previousSource : ferry_sent_config($http, 1))
        ->and($sink->fresh()->config)
        ->toBe($previousSink)
        ->and(Connector::query()->count())
        ->toBe(2);
})->with(['source', 'sink']);

it('does not create a connector record when its first configuration is rejected', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(
        missing_connector_response(),
        new Response(500, [], '{"error_code":500,"message":"Rejected configuration"}'),
    );

    expect(fn () => ferry_connector_manager($http)->activate($sync))
        ->toThrow(ApiException::class)
        ->and(Connector::query()->count())
        ->toBe(0);
});

it('deletes the sink connector and refreshes the shared source connector config', function () {
    $sync = ferry_sync();
    $source = $sync->connection->connector()->create(['name' => 'ferry-source-test-connection']);
    $sink = $sync->connector()->create(['name' => 'ferry-sink-test-sync']);
    $http = new RecordingConnectClient(new Response(204), connector_response(), new Response(202));

    ferry_connector_manager($http)->delete($sync);

    expect(Connector::query()->find($sink->id))
        ->toBeNull()
        ->and($source->fresh()->config)
        ->toBe(ferry_sent_config($http, 1))
        ->and($http->routes())
        ->toBe([
            ['DELETE', '/connectors/ferry-sink-test-sync'],
            ['PUT',    '/connectors/ferry-source-test-connection/config'],
            ['PUT',    '/connectors/ferry-source-test-connection/pause'],
        ]);
});

it('removes the local source connector after deleting it from Kafka Connect', function () {
    $connection = TestCase::makeConnection();
    $connection->connector()->create(['name' => 'ferry-source-test-connection']);
    $http = new RecordingConnectClient(new Response(204));

    ferry_connector_manager($http)->deleteSource($connection);

    expect($connection->fresh()->connector)
        ->toBeNull()
        ->and($http->routes())
        ->toBe([['DELETE', '/connectors/ferry-source-test-connection']]);
});

it('ignores an already missing connector when pausing', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(
        new Response(404, [], '{"error_code":404,"message":"Connector not found"}'),
        connector_response(),
        new Response(404, [], '{"error_code":404,"message":"Connector not found"}'),
    );

    ferry_connector_manager($http)->pause($sync);

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and($sync->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Unknown)
        ->and($http->routes())
        ->toBe([
            ['PUT', '/connectors/ferry-sink-test-sync/pause'],
            ['PUT', '/connectors/ferry-source-test-connection/config'],
            ['GET', '/connectors/ferry-sink-test-sync/status'],
        ]);
});

it('ignores an already missing connector when deleting', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(
        new Response(404, [], '{"error_code":404,"message":"Connector not found"}'),
        connector_response(),
        new Response(202),
    );

    ferry_connector_manager($http)->delete($sync);

    expect($http->routes())->toHaveCount(3);
});

it('records the running source connector status', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $http = new RecordingConnectClient(new Response(200, [], json_encode([
        'name' => 'ferry-source-test-connection',
        'connector' => ['state' => 'RUNNING', 'worker_id' => 'worker:8083'],
        'tasks' => [],
    ], JSON_THROW_ON_ERROR)));

    ferry_connector_manager($http)->sourceStatus($connection);

    expect($connection->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Running)
        ->and($connection->fresh()->connector?->error)
        ->toBeNull();
});

it('records the failed sink connector status with its trace', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(new Response(200, [], json_encode([
        'name' => 'ferry-sink-test-sync',
        'connector' => ['state' => 'FAILED', 'worker_id' => 'worker:8083', 'trace' => 'Boom'],
        'tasks' => [],
    ], JSON_THROW_ON_ERROR)));

    ferry_connector_manager($http)->sinkStatus($sync);

    expect($sync->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Failed)
        ->and($sync->fresh()->connector?->error)
        ->toBe('Boom');
});

it('reports a failed task even when the connector itself reports running', function () {
    $sync = ferry_sync();
    $http = new RecordingConnectClient(new Response(200, [], json_encode([
        'name' => 'ferry-sink-test-sync',
        'connector' => ['state' => 'RUNNING', 'worker_id' => 'worker:8083'],
        'tasks' => [
            ['id' => 0, 'state' => 'FAILED', 'worker_id' => 'worker:8083', 'trace' => 'Task exploded'],
        ],
    ], JSON_THROW_ON_ERROR)));

    ferry_connector_manager($http)->sinkStatus($sync);

    expect($sync->fresh()->connector?->status)
        ->toBe(ConnectorStatus::Failed)
        ->and($sync->fresh()->connector?->error)
        ->toBe('Task exploded');
});

final class RecordingSignalProducer implements SignalProducer
{
    /** @var list<array{topic: string, key: string, payload: string}> */
    public array $published = [];

    public function publish(string $topic, string $key, string $payload): void
    {
        $this->published[] = ['topic' => $topic, 'key' => $key, 'payload' => $payload];
    }
}

final class RecordingConnectClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new LogicException('No queued response.');
    }

    /** @return list<array{string, string}> */
    public function routes(): array
    {
        return array_map(static fn (RequestInterface $request) => [
            $request->getMethod(),
            $request->getUri()->getPath(),
        ], $this->requests);
    }
}
