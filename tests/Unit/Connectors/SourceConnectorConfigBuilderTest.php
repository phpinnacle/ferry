<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\Connectors\SourceConnectorConfigBuilder;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

$makeObject = fn (Connection $connection, array $attributes = []) => ConnectionMetadata::create([
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
    ...$attributes,
]);

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
    $makeObject,
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'title', 'column' => 'title'],
        ]),
    ], ['status' => SyncStatus::Active]);

    $connection = $connection->fresh();
    $builder = new SourceConnectorConfigBuilder;
    $config = $builder->build($connection, $connection->trackedSyncs());

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
        ->and($sync->exists)
        ->toBeTrue();
});

it('isolates the replication slot and publication of every connection', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = new SourceConnectorConfigBuilder()->build($connection, $connection->trackedSyncs());

    expect($config['slot.name'])
        ->toBe('ferry_slot_test_connection')
        ->and($config['publication.name'])
        ->toBe('ferry_pub_test_connection')
        ->and($config['publication.autocreate.mode'])
        ->toBe('filtered');
});

it('always enables the kafka signal channel for ad hoc incremental snapshots, scoped or not', function () use (
    $makeObject,
) {
    $scoped = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $unscoped = TestCase::makeConnection(['code' => 'other-connection']);

    $makeObject($scoped);

    TestCase::makeSync([
        'connection_id' => $scoped->id,
        'source' => 'object-0',
    ], ['status' => SyncStatus::Active]);

    $scoped = $scoped->fresh();

    $builder = new SourceConnectorConfigBuilder;

    foreach ([$scoped, $unscoped] as $connection) {
        $config = $builder->build($connection, $connection->trackedSyncs());

        expect($config['read.only'])
            ->toBe('true')
            ->and($config['signal.enabled.channels'])
            ->toBe('kafka')
            ->and($config['signal.kafka.topic'])
            ->toBe('ferry-signal')
            ->and($config['signal.kafka.bootstrap.servers'])
            ->toBe('kafka.test:9092')
            ->and($config['signal.kafka.groupId'])
            ->toBe('ferry-signal');
    }
});

it('pins the wire format on the connector instead of the shared worker', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    $config = new SourceConnectorConfigBuilder()->build($connection, $connection->trackedSyncs());

    expect($config['key.converter.schemas.enable'])
        ->toBe('true')
        ->and($config['value.converter.schemas.enable'])
        ->toBe('true')
        ->and($config['value.converter'])
        ->toBe('org.apache.kafka.connect.json.JsonConverter');
});

it('unions tables and columns across the tracked synchronizations of one connection', function () use (
    $makeObject,
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $first = $makeObject($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);
    $second = $makeObject($connection, [
        'external_id' => 'object-1',
        'name' => '_reference1',
        'properties' => [$makeProperty('name', '_fld2', new StringField(length: 100, fixed: false))],
    ]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-a',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($first, [['source' => 'title', 'column' => 'title']]),
    ], ['status' => SyncStatus::Active]);

    TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-b',
        'source' => 'object-1',
        'schema' => app(SyncSchemaBuilder::class)->build($second, [['source' => 'name', 'column' => 'name']]),
    ], ['status' => SyncStatus::Pause]);

    $connection = $connection->fresh();
    $config = new SourceConnectorConfigBuilder()->build($connection, $connection->trackedSyncs());

    expect(explode(',', $config['table.include.list']))
        ->toBe(['public._reference0', 'public._reference1'])
        ->and(explode(';', $config['message.key.columns']))
        ->toBe(['public._reference0:_idrref', 'public._reference1:_idrref']);
});
