<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Data\CapturePlan;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\RosettaAdapter;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

require_once __DIR__ . '/../../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('builds a debezium postgres connector config scoped to the tracked replicas', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => ['_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld1', target: 'title'),
        ],
    ], ['status' => ReplicaStatus::Active]);

    $upstream = $upstream->fresh();
    $builder = app(SourceFactory::class);
    $config = $builder->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($builder->name($upstream))
        ->toBe('ferry-source-test-upstream')
        ->and($config['connector.class'])
        ->toBe('io.debezium.connector.postgresql.PostgresConnector')
        ->and($config['database.hostname'])
        ->toBe('localhost')
        ->and($config['database.dbname'])
        ->toBe('db')
        ->and($config['topic.prefix'])
        ->toBe('ferry.test-upstream')
        ->and($config['binary.handling.mode'])
        ->toBe('hex')
        ->and($config['table.include.list'])
        ->toBe('public._reference0')
        ->and(explode(',', $config['column.include.list']))
        ->toContain('public._reference0._idrref', 'public._reference0._fld1')
        ->and($config['message.key.columns'])
        ->toBe('public._reference0:_idrref')
        ->and($builder->topic($upstream, $object))
        ->toBe('ferry.test-upstream.public._reference0')
        ->and($config)
        ->toMatchArray([
            'slot.name' => 'ferry_slot_test_upstream',
            'publication.name' => 'ferry_pub_test_upstream',
            'publication.autocreate.mode' => 'filtered',
            'read.only' => 'true',
            'signal.enabled.channels' => 'kafka',
            'signal.kafka.topic' => 'ferry-signal',
            'signal.kafka.bootstrap.servers' => 'kafka.test:9092',
            'signal.kafka.groupId' => 'ferry-signal',
            'include.unknown.datatypes' => 'true',
            'converters' => 'uuid,integer,mchar',
            'uuid.type' => 'org.phpinnacle.toblerone.UuidConverter',
            'integer.type' => 'org.phpinnacle.toblerone.IntegerConverter',
            'mchar.type' => 'org.phpinnacle.toblerone.MCharConverter',
        ]);
});

it('groups captured column annotations across replicas without inferring converters from names', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $upstream->forceFill(['config' => [...$upstream->config, 'schema' => 'warehouse']])->saveQuietly();
    $object = TestCase::makeItem($upstream, [
        'name' => 'records',
        'primary_key' => ['record_id'],
        'sources' => [
            'record_id' => ['type' => 'string', 'x-ferry-converter' => 'uuid'],
            'type_code' => ['type' => 'integer', 'x-ferry-converter' => 'integer'],
            'filter_id' => ['type' => 'string', 'x-ferry-converter' => 'uuid'],
            'unused_id' => ['type' => 'string', 'x-ferry-converter' => 'uuid'],
            'plain_rref' => ['type' => 'string'],
        ],
    ]);
    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'first',
        'mapping' => [
            new FieldMapping(source: 'record_id', target: 'id'),
            new FieldMapping(source: 'type_code', target: 'type_code'),
            new FieldMapping(source: 'plain_rref', target: 'plain'),
            new FieldMapping(source: 'unused_id', target: null),
        ],
    ], ['status' => ReplicaStatus::Active]);
    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'second',
        'mapping' => [
            new FieldMapping(source: 'record_id', target: 'id'),
            new FieldMapping(source: 'type_code', target: 'type_code'),
            new FieldMapping(source: 'filter_id', target: null, options: [
                'transforms' => [[
                    'type' => 'filter',
                    'data' => [
                        'mode' => 'exclude',
                        'condition' => '@ != null',
                        'missing_or_null_behavior' => 'include',
                    ],
                ]],
            ]),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($config['uuid.columns'])
        ->toBe('warehouse.records.record_id,warehouse.records.filter_id')
        ->and($config['integer.columns'])
        ->toBe('warehouse.records.type_code')
        ->and($config['column.include.list'])
        ->toBe(
            'warehouse.records.record_id,warehouse.records.type_code,warehouse.records.plain_rref,warehouse.records.filter_id',
        )
        ->and($config)
        ->not->toHaveKey('mchar.columns');
});

it('builds source transforms from the origin configuration in order', function () {
    config([
        'phpinnacle-ferry.origins.kafka.source.transforms' => [
            'flatten' => [
                'type' => 'io.debezium.transforms.ExtractNewRecordState',
                'delete.tombstone.handling.mode' => 'rewrite',
            ],
            'route' => [
                'type' => 'org.apache.kafka.connect.transforms.RegexRouter',
                'regex' => '(.*)',
                'replacement' => 'prefix_$1',
            ],
        ],
    ]);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($config)
        ->toMatchArray([
            'transforms' => 'flatten,route',
            'transforms.flatten.type' => 'io.debezium.transforms.ExtractNewRecordState',
            'transforms.flatten.delete.tombstone.handling.mode' => 'rewrite',
            'transforms.route.type' => 'org.apache.kafka.connect.transforms.RegexRouter',
            'transforms.route.regex' => '(.*)',
            'transforms.route.replacement' => 'prefix_$1',
        ])
        ->not->toHaveKey('transforms.unwrap.type');
});

it('registers the configured converter classes even without per-column selections', function () {
    config(['phpinnacle-ferry.origins.kafka.source.converters' => ['text' => 'example.TextConverter']]);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($config)
        ->toMatchArray(['converters' => 'text', 'text.type' => 'example.TextConverter'])
        ->not->toHaveKeys(['text.columns', 'uuid.type', 'integer.type', 'mchar.type']);

    config(['phpinnacle-ferry.origins.kafka.source.converters' => []]);
    expect(app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    ))
        ->not
        ->toHaveKey('converters');
});

it('fingerprints the final Avro value after origin and upstream transforms without topic predicates', function (
    bool $avro,
    ?array $columns,
) {
    $upstream = TestCase::makeUpstream([
        'transforms' => [new TransformSetting('unwrap', ['delete_mode' => 'tombstone'])],
    ]);
    $capture = new CapturePlan(
        $upstream->config,
        ['public.customers'],
        $columns,
        converters: [
            'value.converter' => $avro
                ? 'io.confluent.connect.avro.AvroConverter'
                : 'org.apache.kafka.connect.json.JsonConverter',
        ],
    );
    $settings = config('phpinnacle-ferry.origins.kafka.source');
    $settings['transforms'] = [
        'headers' => [
            'type' => 'org.apache.kafka.connect.transforms.DropHeaders',
            'headers' => 'debug',
            'predicate' => 'tombstone',
            'negate' => 'true',
        ],
        'route' => [
            'type' => 'org.apache.kafka.connect.transforms.RegexRouter',
            'regex' => '(.*)',
            'replacement' => 'prefix_$1',
        ],
    ];

    $config = app(SourceFactory::class)->source($upstream, $capture, $settings);

    expect($config)->toMatchArray([
        'transforms' => $avro ? 'headers,route,upstream_0,schema_fingerprint' : 'headers,route,upstream_0',
        'predicates' => 'tombstone',
        'predicates.tombstone.type' => 'org.apache.kafka.connect.transforms.predicates.RecordIsTombstone',
        'transforms.upstream_0.type' => 'io.debezium.transforms.ExtractNewRecordState',
        'transforms.upstream_0.delete.tombstone.handling.mode' => 'tombstone',
    ]);

    if ($avro) {
        expect($config)->toMatchArray([
            'transforms.schema_fingerprint.type' => 'org.phpinnacle.toblerone.SchemaFingerprintTransform',
        ]);
    } else {
        expect($config)->not->toHaveKey('transforms.schema_fingerprint.type');
    }
})->with([
    'selected Avro columns' => [true, ['public.customers.id']],
    'all Avro columns' => [true, null],
    'JSON' => [false, ['public.customers.id']],
]);

it('unions tables and columns across the tracked replicas of one upstream', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $first = TestCase::makeItem($upstream, [
        'sources' => ['_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
        'fields' => ['_idrref' => Schema::fromArray(['type' => 'string', 'x-ferry-converter' => 'uuid'])],
    ]);
    $second = TestCase::makeItem($upstream, [
        'external_id' => 'object-1',
        'name' => '_reference1',
        'sources' => ['_fld2' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Name']],
        'fields' => ['_idrref' => Schema::fromArray(['type' => 'string', 'x-ferry-converter' => 'uuid'])],
    ]);

    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-a',
        'source' => 'object-0',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld1', target: 'title'),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-b',
        'source' => 'object-1',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld2', target: 'name'),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $upstream = $upstream->fresh();
    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect(explode(',', $config['table.include.list']))
        ->toBe(['public._reference0', 'public._reference1'])
        ->and(explode(';', $config['message.key.columns']))
        ->toBe(['public._reference0:_idrref', 'public._reference1:_idrref'])
        ->and($config['uuid.columns'])
        ->toBe('public._reference0._idrref,public._reference1._idrref');
});

it('never lets an unscoped source connector capture the whole database', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($config['table.include.list'])
        ->toBe('(?!)')
        ->and($config)
        ->not
        ->toHaveKey('message.key.columns')
        ->and($config['signal.enabled.channels'])
        ->toBe('kafka');
});

it('leaves pending static replicas without a selected key or captured columns', function (
    array $primaryKey,
) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'names',
        label: 'Names',
        table: 'names',
        primaryKey: ['tenant_id', 'record_id'],
        schema: SchemaBuilder::object([
            'tenant_id' => Schema::integer(),
            'record_id' => Schema::string(),
            'name' => Schema::string(),
        ])
            ->required(['tenant_id', 'record_id'])
            ->build(),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'name' => 'customers',
        'primary_key' => $primaryKey,
        'sources' => [
            'tenant_id' => ['type' => 'integer'],
            'record_id' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
        ],
    ]);
    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'names',
        'mapping' => [],
    ]);

    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );

    expect($config['table.include.list'])->toBe('public.customers');
    expect($config['column.include.list'])->toBe('');
    expect($config)->not->toHaveKey('message.key.columns');
})->with([
    'composite in declared order' => [['tenant_id', 'record_id']],
    'without a declared primary key' => [[]],
]);

it('uses static mapping keys without a primary key from a non-Rosetta adapter', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'name' => 'customers',
        'primary_key' => [],
        'fields' => [],
        'schema' => Schema::fromArray([
            'type' => 'object',
            'properties' => [
                'record_id' => ['type' => 'string', 'title' => 'ID'],
                'display_name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]),
    ]);
    app(DestinationFactory::class)->register(new StaticDestination(
        'customers',
        'Customers',
        'customers',
        ['id'],
        Schema::object(['id' => Schema::string(), 'name' => Schema::string()]),
    ));
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => [
            new FieldMapping(source: 'record_id', target: 'id'),
            new FieldMapping(source: 'display_name', target: 'name'),
        ],
    ], ['status' => ReplicaStatus::Active]);
    $config = app(SourceFactory::class)->source(
        $upstream,
        app(RosettaAdapter::class)->capture($upstream),
        config('phpinnacle-ferry.origins.kafka.source'),
    );
    $pipeline = app(\PHPinnacle\Ferry\Origins\Kafka\TransformFactory::class)->pipeline(
        $replica,
        $object,
        $replica->primaryKeyMap(['id']),
    );

    expect($config['message.key.columns'])
        ->toBe('public.customers:record_id')
        ->and($config['column.include.list'])
        ->toBe('public.customers.record_id,public.customers.display_name')
        ->and($pipeline['transforms.rename_keys.renames'])
        ->toBe('record_id:id')
        ->and($replica->columnMap())
        ->toBe(['record_id' => 'id', 'display_name' => 'name']);
});
