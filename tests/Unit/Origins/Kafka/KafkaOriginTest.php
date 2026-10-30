<?php

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\Driver as DatabaseDriver;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Origins\Kafka\Contracts\SignalProducer;
use PHPinnacle\Ferry\Origins\Kafka\KafkaConnectFactory;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Origins\KafkaOrigin;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Franz\Client;
use PHPinnacle\Franz\Exception\ApiException;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../../../TestCase.php';
require_once __DIR__ . '/../../../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'database.connections.sqlite.host' => 'db.internal',
        'database.connections.sqlite.port' => 5432,
    ]);
});

function ferry_replica(): PHPinnacle\Ferry\Models\Replica
{
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeItem($upstream);

    return TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
    ], ['status' => ReplicaStatus::Active]);
}

function ferry_kafka_origin(
    RecordingConnectClient $http,
    ?RecordingSignalProducer $signals = null,
    ?DestinationFactory $destinations = null,
): KafkaOrigin {
    $factory = new HttpFactory;
    $client = new Client('http://connect.example:8083', $http, $factory, $factory);
    $destinations ??= new DestinationFactory(app(ReplicaSchemaResolver::class));
    $transforms = app(TransformFactory::class);

    $config = config('phpinnacle-ferry.origins.kafka');
    $kafkaConnect = Mockery::mock(KafkaConnectFactory::class);
    $kafkaConnect->shouldReceive('client')->with($config)->andReturn($client);
    $kafkaConnect
        ->shouldReceive('signals')
        ->with($config)
        ->andReturn($signals ?? new RecordingSignalProducer);

    return new KafkaOrigin(
        'kafka',
        $config,
        $kafkaConnect,
        app(SourceFactory::class),
        $destinations,
        new SinkConfigFactory($transforms, app(ReplicaSchemaResolver::class)),
        app(\PHPinnacle\Ferry\Rules\ReplicaSchema::class),
        app(ReplicaSchemaResolver::class),
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
        $this->responses = $responses;
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

it('builds source configuration from pending and paused replicas without saving', function () {
    $replica = ferry_replica();
    $upstream = $replica->upstream;
    TestCase::makeItem($upstream, ['external_id' => 'object-1', 'name' => '_reference1']);
    TestCase::makeReplica(['upstream_id' => $upstream->id, 'code' => 'pending', 'source' => 'object-1']);
    $replica->pause();
    $http = new RecordingConnectClient;
    $origin = ferry_kafka_origin($http);
    $upstream->refresh();
    $before = $upstream->connector->config;
    $connector = $origin->make($upstream);

    expect($connector->is($upstream->connector))
        ->toBeTrue()
        ->and(explode(',', $connector->config['table.include.list']))
        ->toEqualCanonicalizing(['public._reference0', 'public._reference1'])
        ->and($connector->origin)
        ->toBe('kafka')
        ->and($upstream->fresh()->connector->config)
        ->toBe($before)
        ->and($http->requests)
        ->toBe([]);
});

it('builds source configuration matching no tables after the last replica is removed', function () {
    $replica = ferry_replica();
    $upstream = $replica->upstream;
    $replica->delete();
    $connector = ferry_kafka_origin(new RecordingConnectClient)->make($upstream);
    expect($connector->config['table.include.list'])->toBe('(?!)');
});

it('applies the named origin transport settings to source and sink connectors', function (array $settings) {
    $replica = ferry_replica();
    $defaults = config('phpinnacle-ferry.origins.kafka');
    $origins = new OriginFactory(app(), [
        'kafka' => $defaults,
        'analytics' => [...$defaults, 'source' => $settings, 'sink' => $settings],
    ]);

    foreach (['kafka' => $defaults['source'], 'analytics' => $settings] as $name => $expected) {
        $origin = $origins->create($name);

        foreach ([$replica->upstream, $replica] as $owner) {
            $config = $origin->make($owner)->config;
            $actual = array_filter($config, fn (string $key) => str_contains($key, '.converter'), ARRAY_FILTER_USE_KEY);

            expect($actual)->toBe($expected);
        }
    }
})->with([
    'custom converters and properties' => [[
        'key.converter' => 'io.confluent.connect.avro.AvroConverter',
        'key.converter.schema.registry.url' => 'http://registry.test:8081',
        'value.converter' => 'io.confluent.connect.avro.AvroConverter',
        'value.converter.schema.registry.url' => 'http://registry.test:8081',
    ]],
    'worker defaults' => [[]],
]);

it('applies arbitrary origin settings only to their source or sink and overrides generated values', function () {
    $replica = ferry_replica();
    $sourceSettings = [
        'tasks.max' => '2',
        'converters' => 'uuid',
        'uuid.type' => 'org.phpinnacle.toblerone.UuidConverter',
        'uuid.columns' => 'public._reference0._idrref',
        'include.unknown.datatypes' => 'true',
    ];
    $sinkSettings = ['tasks.max' => '3', 'batch.size' => '500'];
    $origin = new OriginFactory(app(), [
        'analytics' => [
            ...config('phpinnacle-ferry.origins.kafka'),
            'source' => $sourceSettings,
            'sink' => $sinkSettings,
        ],
    ])->create('analytics');

    expect($origin->make($replica->upstream)->config)
        ->toMatchArray($sourceSettings)
        ->not->toHaveKey('batch.size')->and($origin->make($replica)->config)->toMatchArray($sinkSettings)
        ->not->toHaveKeys(['converters', 'uuid.type', 'uuid.columns', 'include.unknown.datatypes']);
});

it('uses each named origin target host only for its sink', function () {
    $replica = ferry_replica();
    $settings = config('phpinnacle-ferry.origins.kafka');
    $origins = new OriginFactory(app(), [
        'kafka' => $settings,
        'analytics' => [...$settings, 'target_host' => 'analytics-db.internal'],
    ]);

    foreach (['kafka' => 'db.internal', 'analytics' => 'analytics-db.internal'] as $name => $host) {
        $origin = $origins->create($name);

        expect($origin->make($replica)->config['connection.url'])
            ->toBe(sprintf('jdbc:postgresql://%s:5432/:memory:', $host))
            ->and($origin->make($replica->upstream)->config['database.hostname'])
            ->toBe('localhost');
    }
});

it('extracts new record state at the source and preserves deletes for every destination', function (bool $static) {
    $replica = ferry_replica();
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'customers',
        label: 'Customers',
        table: 'customers',
        primaryKey: ['customer_id'],
        schema: SchemaBuilder::make(Schema::object([
            'customer_id' => SchemaBuilder::make()->type('string')->format('uuid')->build(),
            'name' => Schema::string(),
            'tax_number' => Schema::string(),
        ]))
            ->required(['customer_id', 'name', 'tax_number'])
            ->build(),
        fixedValues: customers_destination()->getFixedValues(),
    ));
    if ($static) {
        $replica->forceFill([
            'type' => DestinationType::Static,
            'destination' => 'customers',
            'mapping' => [
                new FieldMapping(source: '_idrref', target: 'customer_id'),
                FieldMapping::fromArray([
                    'kind' => 'static',
                    'target' => 'name',
                    'static' => 'Customer',
                    'options' => [],
                ]),
                FieldMapping::fromArray([
                    'kind' => 'static',
                    'target' => 'tax_number',
                    'static' => '123',
                    'options' => [],
                ]),
            ],
        ])->saveQuietly();
    }

    $settings = config('phpinnacle-ferry.origins.kafka');
    $origins = new OriginFactory(app(), [
        'kafka' => $settings,
        'analytics' => $settings,
    ]);

    foreach (['kafka', 'analytics'] as $name) {
        $origin = $origins->create($name);
        $source = $origin->make($replica->upstream)->config;
        $sink = $origin->make($replica)->config;

        expect($source)->toMatchArray([
            'transforms' => 'unwrap',
            'transforms.unwrap.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.unwrap.delete.tombstone.handling.mode' => 'tombstone',
        ]);
        expect($sink)->not->toHaveKeys([
            'transforms.unwrap.type',
            'transforms.unwrap.delete.tombstone.handling.mode',
            'transforms.drop_tombstones.type',
        ]);
        expect($sink['transforms'])
            ->toBe(
                $static
                    ? 'field_1_0,field_2_0,insert_type,insert_profile_id,rename_keys,rename_values'
                    : 'rename_keys,rename_values',
            );

        expect($sink['delete.enabled'])->toBe('true');
        expect($sink['pk.mode'])->toBe('record_key');
        expect($sink['transforms.rename_keys.include'])->toBe('_idrref');
        expect($sink['transforms.rename_keys.renames'])->toBe($static ? '_idrref:customer_id' : '_idrref:external_id');
    }
})->with(['dynamic' => [false], 'static' => [true]]);

it('builds sink configuration through the destination registry', function (?string $destination) {
    $replica = ferry_replica();
    $destinations = app(DestinationFactory::class);
    $destinations->register(customers_destination());
    if ($destination !== null) {
        $replica->forceFill([
            'type' => DestinationType::Static,
            'destination' => $destination,
            'mapping' => [
                new FieldMapping(source: '_idrref', target: 'customer_id'),
                FieldMapping::fromArray([
                    'kind' => 'static',
                    'target' => 'name',
                    'static' => 'Customer',
                    'options' => [],
                ]),
                FieldMapping::fromArray([
                    'kind' => 'static',
                    'target' => 'tax_number',
                    'static' => '123',
                    'options' => [],
                ]),
            ],
        ])->saveQuietly();
    }

    $http = new RecordingConnectClient;
    $connector = ferry_kafka_origin($http, destinations: $destinations)->make($replica);
    expect($connector->config['connector.class'])
        ->toBe('io.confluent.connect.jdbc.JdbcSinkConnector')
        ->and($connector->config['table.name.format'])
        ->toBe($destination ?? $replica->destination)
        ->and($connector->config['delete.enabled'])
        ->toBe('true')
        ->and($http->requests)
        ->toBe([]);
})->with(['dynamic' => [null], 'static' => ['customers']]);

it('rejects unsupported source drivers during assembly', function (bool $source) {
    $replica = ferry_replica();
    $upstream = $replica->upstream;
    $upstream->forceFill(['driver' => DatabaseDriver::Sqlsrv])->saveQuietly();
    $replica->unsetRelation('upstream');
    $http = new RecordingConnectClient;
    expect(fn () => ferry_kafka_origin($http)->make($source ? $upstream : $replica))
        ->toThrow(LogicException::class)
        ->and($http->requests)
        ->toBe([]);
})->with([true, false]);

it('rejects missing source objects and incompatible mappings during assembly', function (bool $missing) {
    $replica = ferry_replica();
    if ($missing) {
        $replica->upstream->structure->publishedItem($replica->source)->delete();
    } else {
        $replica->mapping = [new FieldMapping(source: 'missing', target: 'external_id')];
    }

    $http = new RecordingConnectClient;
    expect(fn () => ferry_kafka_origin($http)->make($replica))
        ->toThrow($missing ? LogicException::class : ValidationException::class)
        ->and($http->requests)
        ->toBe([]);
})->with([true, false]);

it('rejects all saved primary-key transforms during assembly', function (
    DestinationType $type,
    string $transform,
    array $parameters,
) {
    $replica = ferry_replica();
    $destination = new StaticDestination(
        'keyed',
        'Keyed',
        'keyed',
        ['id'],
        SchemaBuilder::make(Schema::object([
            'id' => Schema::string(),
        ]))
            ->required(['id'])
            ->build(),
    );
    $destinations = new DestinationFactory(app(ReplicaSchemaResolver::class));
    $destinations->register($destination);
    $replica->type = $type;
    $replica->destination = $type === DestinationType::Static ? 'keyed' : $replica->destination;
    $replica->mapping = [new FieldMapping(source: '_idrref', target: 'id', options: [
        'transforms' => [['type' => $transform, 'data' => $parameters]],
    ])];
    $http = new RecordingConnectClient;
    expect(fn () => ferry_kafka_origin($http, destinations: $destinations)->make($replica))
        ->toThrow(ValidationException::class)
        ->and($http->requests)
        ->toBe([]);
})->with([DestinationType::Dynamic, DestinationType::Static])->with([
    'filter' => ['filter', ['condition' => '@ != null']],
    'trim' => ['trim', []],
    'cast' => ['cast', ['type' => 'int64']],
]);

it('compiles both configurations before saving either connector', function () {
    $replica = ferry_replica();
    $source = $replica->upstream->connector;
    $sink = $replica->connector;
    $previousSource = $source->config;
    $previousSink = $sink->config;
    $replica->forceFill(['mapping' => [new FieldMapping(source: '_idrref', target: 'external_id', options: [
        'transforms' => [['type' => 'missing', 'data' => []]],
    ])]])->saveQuietly();
    $http = new RecordingConnectClient;
    $origin = ferry_kafka_origin($http);
    $origins = Mockery::mock(OriginFactory::class);
    $origins->shouldReceive('for')->andReturn($origin);
    app()->instance(OriginFactory::class, $origins);
    expect(fn () => $replica->update(['name' => 'Changed']))
        ->toThrow(ValidationException::class)
        ->and($source->fresh()->config)
        ->toBe($previousSource)
        ->and($sink->fresh()->config)
        ->toBe($previousSink)
        ->and($http->requests)
        ->toBe([]);
});

it('creates connectors with an idempotent configuration PUT and does not persist models', function () {
    $replica = ferry_replica();
    $http = new RecordingConnectClient(connector_response(), connector_response());
    $origin = ferry_kafka_origin($http);
    $connector = $origin->make($replica);
    $before = $connector->fresh()->config;
    $origin->create($connector);
    $origin->create($connector);
    expect($http->routes())
        ->toBe([
            ['PUT', '/connectors/ferry-sink-test-replica/config'],
            ['PUT', '/connectors/ferry-sink-test-replica/config'],
        ])
        ->and(ferry_sent_config($http, 0))
        ->toBe($connector->config)
        ->and($connector->fresh()->config)
        ->toBe($before);
});

it('sends generated source converters to Kafka Connect without adding them to the sink', function () {
    $replica = ferry_replica();
    $object = $replica->sourceObject();
    $object->update(['schema' => JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['_idrref' => [...$object->field('_idrref')->toArray(), 'x-ferry-converter' => 'uuid']],
    ])]);
    $http = new RecordingConnectClient(connector_response());
    $origin = ferry_kafka_origin($http);
    $connector = $origin->make($replica->upstream);

    $origin->create($connector);

    expect(ferry_sent_config($http, 0))
        ->toMatchArray([
            'converters' => 'uuid,integer,mchar',
            'uuid.type' => 'org.phpinnacle.toblerone.UuidConverter',
            'uuid.columns' => 'public._reference0._idrref',
            'integer.type' => 'org.phpinnacle.toblerone.IntegerConverter',
            'mchar.type' => 'org.phpinnacle.toblerone.MCharConverter',
            'include.unknown.datatypes' => 'true',
        ]);

    $replica->mapping = [new FieldMapping(source: '_idrref', target: 'external_id')];
    expect($origin->make($replica)->config)
        ->not
        ->toHaveKeys(['converters', 'uuid.type', 'uuid.columns', 'integer.type', 'mchar.type']);
});

it('requests snapshots for newly captured columns using the last applied configuration', function (
    string $previousColumns,
    array $tables,
) {
    $replica = ferry_replica();
    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(connector_response());
    $origin = ferry_kafka_origin($http, $signals);
    $connector = $origin->make($replica->upstream);
    $connector->applied_config = ['column.include.list' => $previousColumns];
    $origin->update($connector);
    expect(count($signals->published))->toBe($tables === [] ? 0 : 1);
    if ($tables !== []) {
        $signal = $signals->published[0];
        expect($signal['topic'])
            ->toBe('ferry-signal')
            ->and($signal['key'])
            ->toBe('ferry.test-upstream')
            ->and(json_decode($signal['payload'], true))
            ->toBe([
                'type' => 'execute-snapshot',
                'data' => ['data-collections' => $tables, 'type' => 'INCREMENTAL'],
            ]);
    }
})->with([
    'new table' => ['', ['public._reference0']],
    'unchanged' => ['public._reference0._idrref', []],
    'removed column' => ['public._reference0._idrref,public._reference0._description', []],
]);

it('does not request source snapshots when updating a sink', function () {
    $replica = ferry_replica();
    $signals = new RecordingSignalProducer;
    $http = new RecordingConnectClient(connector_response());
    $origin = ferry_kafka_origin($http, $signals);
    $connector = $origin->make($replica);
    $connector->applied_config = $connector->config;
    $origin->update($connector);
    expect($signals->published)->toBe([]);
});

it('resumes processing and restarts failed tasks when needed', function (bool $failed, bool $source) {
    $replica = ferry_replica();
    $connector = $source ? $replica->upstream->connector : $replica->connector;
    $connector->status = $failed ? ConnectorStatus::Failed : ConnectorStatus::Paused;
    $http = new RecordingConnectClient(...$failed ? [new Response(202), new Response(202)] : [new Response(202)]);
    ferry_kafka_origin($http)->start($connector);
    expect($http->routes())
        ->toBe(
            $failed
                ? [
                    ['POST', '/connectors/' . $connector->name . '/restart'],
                    ['PUT', '/connectors/' . $connector->name . '/resume'],
                ] : [['PUT', '/connectors/' . $connector->name . '/resume']],
        );
    if ($failed) {
        expect($http->requests[0]->getUri()->getQuery())->toBe('includeTasks=true&onlyFailed=true');
    }
})->with(['failed' => true, 'paused' => false])->with(['source' => true, 'sink' => false]);

it('treats missing resources as successful pause or removal', function (string $operation) {
    $connector = ferry_replica()->connector;
    $http = new RecordingConnectClient(missing_connector_response(), missing_connector_response());
    $origin = ferry_kafka_origin($http);
    $origin->{$operation}($connector);
    $origin->{$operation}($connector);
    expect($connector->fresh())->not->toBeNull()->and(count($http->requests))->toBe(2);
})->with(['pause', 'remove']);

it('propagates remote failures without changing persisted state', function (string $operation) {
    $replica = ferry_replica();
    $http = new RecordingConnectClient(new Response(500, [], '{"error_code":500,"message":"Operation failed"}'));
    $origin = ferry_kafka_origin($http);
    $connector = $origin->make($replica);
    $connector->applied_config = $connector->config;
    $before = $connector->fresh()->getAttributes();
    expect(fn () => $origin->{$operation}($connector))
        ->toThrow(ApiException::class)
        ->and($connector->fresh()->getAttributes())
        ->toBe($before);
})->with(['create', 'update', 'start', 'pause', 'remove', 'status']);

it('returns normalized statuses and diagnostics without saving', function (
    string $remote,
    array $tasks,
    ConnectorStatus $expected,
    ?string $error,
) {
    $connector = ferry_replica()->connector;
    $http = new RecordingConnectClient(new Response(200, [], json_encode([
        'name' => 'x',
        'connector' => ['state' => $remote, 'worker_id' => 'worker:8083', 'trace' => null],
        'tasks' => $tasks,
    ], JSON_THROW_ON_ERROR)));
    $status = ferry_kafka_origin($http)->status($connector);
    expect($status)
        ->toBe($expected)
        ->and($connector->error)
        ->toBe($error)
        ->and($connector->fresh()->status)
        ->toBeNull();
})->with([
    'running' => ['RUNNING', [], ConnectorStatus::Running, null],
    'paused' => ['PAUSED', [], ConnectorStatus::Paused, null],
    'stopped' => ['STOPPED', [], ConnectorStatus::Paused, null],
    'failed task' => [
        'RUNNING',
        [['id' => 0, 'state' => 'FAILED', 'worker_id' => 'worker:8083', 'trace' => 'Task exploded']],
        ConnectorStatus::Failed,
        'Task exploded',
    ],
]);

it('returns unknown for missing connectors and clears previous errors', function () {
    $connector = ferry_replica()->connector;
    $connector->error = 'Previous error';
    expect(ferry_kafka_origin(new RecordingConnectClient(missing_connector_response()))->status($connector))
        ->toBe(ConnectorStatus::Unknown)
        ->and($connector->error)
        ->toBeNull();
});

it('keeps named origin settings isolated and captured by each instance', function () {
    config([
        'phpinnacle-ferry.origins.analytics' => [
            ...config('phpinnacle-ferry.origins.kafka'),
            'base_uri' => 'http://analytics.test:8083',
            'headers' => ['X-Origin' => 'analytics'],
        ],
    ]);
    $http = new RecordingConnectClient(status_response(), status_response());
    $factory = new HttpFactory;
    app()->instance(ClientInterface::class, $http);
    app()->instance(RequestFactoryInterface::class, $factory);
    app()->instance(StreamFactoryInterface::class, $factory);
    $origins = new OriginFactory(app(), config('phpinnacle-ferry.origins'));
    $analytics = $origins->create('analytics');
    config(['phpinnacle-ferry.origins.analytics' => []]);
    $connector = ferry_replica()->connector;
    $analytics->status($connector);
    $origins->create('kafka')->status($connector);
    expect($http->requests[0]->getUri()->getHost())
        ->toBe('analytics.test')
        ->and($http->requests[0]->getHeaderLine('X-Origin'))
        ->toBe('analytics')
        ->and($http->requests[1]->getUri()->getHost())
        ->toBe('kafka-connect.test')
        ->and($http->requests[1]->hasHeader('X-Origin'))
        ->toBeFalse();
});

it('deploys Kafka configurations through the service and job without persisting in Origin', function () {
    $replica = ferry_replica();
    $http = new RecordingConnectClient(
        connector_response(),
        connector_response(),
        new Response(202),
        status_response(),
        new Response(202),
        status_response(),
    );
    $origin = ferry_kafka_origin($http);
    $origins = Mockery::mock(OriginFactory::class);
    $origins->shouldReceive('for')->andReturn($origin);
    $origins->shouldReceive('create')->andReturn($origin);
    app()->instance(OriginFactory::class, $origins);
    $manager = app(\PHPinnacle\Ferry\Services\ConnectorManager::class);
    $manager->activate($replica);
    expect($http->requests)->toBe([])->and($replica->fresh()->status)->toBe(ReplicaStatus::Pending);
    new \PHPinnacle\Ferry\Jobs\Connector\SynchronizeConnectorsJob($replica->upstream_id)->handle($manager);
    expect($http->routes())
        ->toBe([
            ['PUT', '/connectors/ferry-source-test-upstream/config'],
            ['PUT', '/connectors/ferry-sink-test-replica/config'],
            ['PUT', '/connectors/ferry-sink-test-replica/resume'],
            ['GET', '/connectors/ferry-sink-test-replica/status'],
            ['PUT', '/connectors/ferry-source-test-upstream/resume'],
            ['GET', '/connectors/ferry-source-test-upstream/status'],
        ])
        ->and($replica->fresh()->status)
        ->toBe(ReplicaStatus::Active)
        ->and($replica->fresh()->connector->applied_config)
        ->toBe(ferry_sent_config($http, 1));
});
