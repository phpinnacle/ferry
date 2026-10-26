<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

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
            TestCase::keyMapping(),
            new FieldMapping('_fld1', 'title', $object->field('_fld1')),
        ],
    ], ['status' => ReplicaStatus::Active]);

    $upstream = $upstream->fresh();
    $builder = new SourceFactory;
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
            'uuid.type' => 'org.alpaka.kafka.UuidConverter',
            'integer.type' => 'org.alpaka.kafka.IntegerConverter',
            'mchar.type' => 'org.alpaka.kafka.MCharConverter',
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
        'schema' => [
            new FieldMapping('record_id', 'id', $object->field('record_id')),
            new FieldMapping('type_code', 'type_code', $object->field('type_code')),
            new FieldMapping('plain_rref', 'plain', $object->field('plain_rref')),
            new FieldMapping('unused_id', null, $object->field('unused_id')),
        ],
    ], ['status' => ReplicaStatus::Active]);
    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'second',
        'schema' => [
            new FieldMapping('record_id', 'id', $object->field('record_id')),
            new FieldMapping('type_code', 'type_code', $object->field('type_code')),
            new FieldMapping(
                'filter_id',
                null,
                $object->field('filter_id'),
                transforms: [new TransformSetting('filter', ['condition' => '@ != null'])],
            ),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $config = new SourceFactory()->source($upstream, config('phpinnacle-ferry.origins.kafka'));

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

    $config = new SourceFactory()->source($upstream, config('phpinnacle-ferry.origins.kafka'));

    expect($config)
        ->toMatchArray(['converters' => 'text', 'text.type' => 'example.TextConverter'])
        ->not->toHaveKeys(['text.columns', 'uuid.type', 'integer.type', 'mchar.type']);

    config(['phpinnacle-ferry.kafka.converters' => []]);
    expect(new SourceFactory()->source($upstream, config('phpinnacle-ferry.origins.kafka')))
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
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping('_fld1', 'title', $first->field('_fld1')),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-b',
        'source' => 'object-1',
        'schema' => [
            TestCase::keyMapping(),
            new FieldMapping('_fld2', 'name', $second->field('_fld2')),
        ],
    ], ['status' => ReplicaStatus::Pause]);

    $upstream = $upstream->fresh();
    $config = new SourceFactory()->source(
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

    $config = new SourceFactory()->source(
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

it('captures every source primary-key column independently of the static value mapping', function (
    array $primaryKey,
    string $columns,
    ?string $key,
) {
    app(DestinationFactory::class)->register(new StaticDestination(
        key: 'names',
        label: 'Names',
        table: 'names',
        primaryKey: ['name'],
        schema: Schema::object(['name' => Schema::string()])->required(['name']),
    ));
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'name' => 'customers',
        'primary_key' => $primaryKey,
        'sources' => ['display_name' => ['type' => 'string']],
    ]);
    TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'names',
        'schema' => [new FieldMapping('display_name', 'name', $object->field('display_name'))],
    ]);

    $config = new SourceFactory()->source($upstream, config('phpinnacle-ferry.origins.kafka'));

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
        'public.customers.tenant_id,public.customers.record_id,public.customers.display_name',
        'public.customers:tenant_id,record_id',
    ],
    'without a declared primary key' => [[], 'public.customers.display_name', null],
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
        'schema' => [
            new FieldMapping('record_id', 'id', JsonSchema::fromArray(['type' => 'string'])),
            new FieldMapping('display_name', 'name', $object->field('display_name')),
        ],
    ], ['status' => ReplicaStatus::Active]);
    $config = new SourceFactory()->source(
        $upstream,
        config('phpinnacle-ferry.origins.kafka'),
    );
    $pipeline = new \PHPinnacle\Ferry\Origins\Kafka\TransformFactory()->pipeline(
        $replica,
        $object,
        $replica->primaryKeyMap($object),
    );

    expect($config['message.key.columns'])
        ->toBe('public.customers:record_id')
        ->and($config['column.include.list'])
        ->toBe('public.customers.record_id,public.customers.display_name')
        ->and($pipeline['transforms.rename_keys.renames'])
        ->toBe('record_id:id')
        ->and($replica->columnMap($object))
        ->toBe(['record_id' => 'id', 'display_name' => 'name']);
});
