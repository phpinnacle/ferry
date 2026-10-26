<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Tests\TestCase;

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
        'schema' => [
            new FieldMapping('_fld1', 'title', $object->field('_fld1')),
        ],
    ], ['status' => ReplicaStatus::Active]);

    $upstream = $upstream->fresh();
    $builder = new SourceFactory;
    $config = $builder->source(
        $upstream,
        $upstream->trackedReplicas(),
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
            'key.converter.schemas.enable' => 'true',
            'value.converter.schemas.enable' => 'true',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
        ]);
});

it('unions tables and columns across the tracked replicas of one upstream', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $first = TestCase::makeItem($upstream, [
        'sources' => ['_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
    ]);
    $second = TestCase::makeItem($upstream, [
        'external_id' => 'object-1',
        'name' => '_reference1',
        'sources' => ['_fld2' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Name']],
    ]);

    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-a',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_fld1', 'title', $first->field('_fld1')),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-b',
        'source' => 'object-1',
        'schema' => [
            new FieldMapping('_fld2', 'name', $second->field('_fld2')),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $upstream = $upstream->fresh();
    $config = new SourceFactory()->source(
        $upstream,
        $upstream->trackedReplicas(),
        config('phpinnacle-ferry.origins.kafka'),
    );

    expect(explode(',', $config['table.include.list']))
        ->toBe(['public._reference0', 'public._reference1'])
        ->and(explode(';', $config['message.key.columns']))
        ->toBe(['public._reference0:_idrref', 'public._reference1:_idrref']);
});

it('never lets an unscoped source connector capture the whole database', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = new SourceFactory()->source(
        $upstream,
        $upstream->trackedReplicas(),
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

it('uses the key and column names supplied by a non-Rosetta adapter', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'name' => 'customers',
        'key_column' => 'record_id',
        'fields' => [],
        'schema' => JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'display_name' => ['type' => 'string', 'title' => 'Name'],
            ],
        ]),
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            new FieldMapping('display_name', 'name', $object->field('display_name')),
        ],
    ], ['status' => ReplicaStatus::Active]);
    $config = new SourceFactory()->source(
        $upstream,
        $upstream->trackedReplicas(),
        config('phpinnacle-ferry.origins.kafka'),
    );
    $pipeline = new \PHPinnacle\Ferry\Origins\Kafka\TransformFactory()->pipeline($replica, $object);

    expect($config['message.key.columns'])
        ->toBe('public.customers:record_id')
        ->and($config['column.include.list'])
        ->toBe('public.customers.record_id,public.customers.display_name')
        ->and($pipeline['transforms.rename_keys.renames'])
        ->toBe('record_id:idrref')
        ->and($replica->columnMap($object))
        ->toBe(['record_id' => 'idrref', 'display_name' => 'name']);
});
