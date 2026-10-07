<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\SourceFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

$makeProperty = fn (string $id, string $name, object $field) => new MetadataProperty(
    id: $id,
    name: $name,
    code: 2,
    kind: PropertyKind::Field,
    label: ucfirst($id),
    title: ucfirst($id),
    field: $field,
);

it('builds a debezium postgres connector config scoped to the tracked synchronizations', function () use (
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($object, [
            'title' => 'title',
        ]),
    ], ['status' => SyncStatus::Active]);

    $connection = $connection->fresh();
    $builder = new SourceFactory;
    $config = $builder->source($connection, $connection->trackedSyncs());

    expect($builder->name($connection))
        ->toBe('ferry-source-test-connection')
        ->and($config['connector.class'])
        ->toBe('io.debezium.connector.postgresql.PostgresConnector')
        ->and($config['database.hostname'])
        ->toBe('localhost')
        ->and($config['database.dbname'])
        ->toBe('db')
        ->and($config['topic.prefix'])
        ->toBe('ferry.test-connection')
        ->and($config['binary.handling.mode'])
        ->toBe('hex')
        ->and($config['table.include.list'])
        ->toBe('public._reference0')
        ->and(explode(',', $config['column.include.list']))
        ->toContain('public._reference0._idrref', 'public._reference0._fld1')
        ->and($config['message.key.columns'])
        ->toBe('public._reference0:_idrref')
        ->and($builder->topic($connection, $object))
        ->toBe('ferry.test-connection.public._reference0')
        ->and($config)
        ->toMatchArray([
            'slot.name' => 'ferry_slot_test_connection',
            'publication.name' => 'ferry_pub_test_connection',
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

it('unions tables and columns across the tracked synchronizations of one connection', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $first = TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);
    $second = TestCase::makeMetadata($connection, [
        'external_id' => 'object-1',
        'name' => '_reference1',
        'properties' => [$makeProperty('name', '_fld2', new StringField(length: 100, fixed: false))],
    ]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-a',
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($first, ['title' => 'title']),
    ], ['status' => SyncStatus::Active]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-b',
        'source' => 'object-1',
        'schema' => SyncSchema::fromBindings($second, ['name' => 'name']),
    ], ['status' => SyncStatus::Pause]);

    $connection = $connection->fresh();
    $config = new SourceFactory()->source(
        $connection,
        $connection->trackedSyncs(),
    );

    expect(explode(',', $config['table.include.list']))
        ->toBe(['public._reference0', 'public._reference1'])
        ->and(explode(';', $config['message.key.columns']))
        ->toBe(['public._reference0:_idrref', 'public._reference1:_idrref']);
});

it('never lets an unscoped source connector capture the whole database', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = new SourceFactory()->source(
        $connection,
        $connection->trackedSyncs(),
    );

    expect($config)
        ->not->toHaveKey('table.include.list')->and($config)
        ->not->toHaveKey('message.key.columns')->and($config['signal.enabled.channels'])->toBe('kafka');
});
