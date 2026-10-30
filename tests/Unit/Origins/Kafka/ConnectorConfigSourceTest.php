<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
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
        config('phpinnacle-ferry.origins.kafka'),
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
                'transforms' => [['type' => 'filter', 'data' => ['condition' => '@ != null']]],
            ]),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $config = app(SourceFactory::class)->source($upstream, config('phpinnacle-ferry.origins.kafka'));

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

it('registers the configured converter classes even without per-column selections', function () {
    config(['phpinnacle-ferry.kafka.converters' => ['text' => 'example.TextConverter']]);
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = app(SourceFactory::class)->source($upstream, config('phpinnacle-ferry.origins.kafka'));

    expect($config)
        ->toMatchArray(['converters' => 'text', 'text.type' => 'example.TextConverter'])
        ->not->toHaveKeys(['text.columns', 'uuid.type', 'integer.type', 'mchar.type']);

    config(['phpinnacle-ferry.kafka.converters' => []]);
    expect(app(SourceFactory::class)->source($upstream, config('phpinnacle-ferry.origins.kafka')))
        ->not
        ->toHaveKey('converters');
});

it('unions tables and columns across the tracked replicas of one upstream', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $first = TestCase::makeItem($upstream, [
        'sources' => ['_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
        'fields' => ['_idrref' => JsonSchema::fromArray(['type' => 'string', 'x-ferry-converter' => 'uuid'])],
    ]);
    $second = TestCase::makeItem($upstream, [
        'external_id' => 'object-1',
        'name' => '_reference1',
        'sources' => ['_fld2' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Name']],
        'fields' => ['_idrref' => JsonSchema::fromArray(['type' => 'string', 'x-ferry-converter' => 'uuid'])],
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
        config('phpinnacle-ferry.origins.kafka'),
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
        config('phpinnacle-ferry.origins.kafka'),
    );

    expect($config['table.include.list'])
        ->toBe('(?!)')
        ->and($config)
        ->not
        ->toHaveKey('message.key.columns')
        ->and($config['signal.enabled.channels'])
        ->toBe('kafka');
});

it('captures declared source keys before a pending static replica is mapped', function (
    array $primaryKey,
    string $columns,
    ?string $key,
) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'names',
        label: 'Names',
        table: 'names',
        primaryKey: ['tenant_id', 'record_id'],
        schema: SchemaBuilder::make(Schema::object([
            'tenant_id' => Schema::integer(),
            'record_id' => Schema::string(),
            'name' => Schema::string(),
        ]))
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

    $config = app(SourceFactory::class)->source($upstream, config('phpinnacle-ferry.origins.kafka'));

    expect($config['table.include.list'])->toBe('public.customers');
    expect($config['column.include.list'])->toBe($columns);
    if ($key === null) {
        expect($config)->not->toHaveKey('message.key.columns');
    } else {
        expect($config['message.key.columns'])->toBe($key);
    }
})->with([
    'composite in declared order' => [
        ['tenant_id', 'record_id'],
        'public.customers.tenant_id,public.customers.record_id',
        'public.customers:tenant_id,record_id',
    ],
    'without a declared primary key' => [[], '', null],
]);

it('uses the key and column names supplied by a non-Rosetta adapter', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'name' => 'customers',
        'primary_key' => ['record_id'],
        'fields' => [],
        'schema' => JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'record_id' => ['type' => 'string', 'title' => 'ID'],
                'display_name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]),
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'mapping' => [
            new FieldMapping(source: 'record_id', target: 'id'),
            new FieldMapping(source: 'display_name', target: 'name'),
        ],
    ], ['status' => ReplicaStatus::Active]);
    $config = app(SourceFactory::class)->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    );
    $pipeline = app(\PHPinnacle\Ferry\Origins\Kafka\TransformFactory::class)->pipeline(
        $replica,
        $object,
        $replica->primaryKeyMap(app(ReplicaSchemaResolver::class)->primaryKeySources($replica, $object)),
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
