<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\Connectors\SinkConnectorConfigBuilder;
use PHPinnacle\Ferry\Services\Connectors\SourceConnectorConfigBuilder;
use PHPinnacle\Ferry\Services\StaticDestinationRegistry;
use PHPinnacle\Ferry\Services\SyncDestinationResolver;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;
use PHPinnacle\Ferry\Tests\Fakes\FakeCustomersDestination;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../../TestCase.php';
require_once __DIR__ . '/../../Fakes/FakeCustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'database.connections.sqlite.host' => 'db.internal',
        'database.connections.sqlite.port' => 5432,
        'database.connections.sales.host' => 'sales.internal',
        'database.connections.sales.port' => 5432,
        'database.connections.sales.database' => 'prozoo_sales',
        'database.connections.sales.username' => 'sales_user',
        'database.connections.sales.password' => 'sales_secret',
    ]);
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

it('builds a confluent jdbc sink connector config with field renames', function () use ($makeObject, $makeProperty) {
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
    ]);

    $sourceBuilder = new SourceConnectorConfigBuilder;
    $builder = new SinkConnectorConfigBuilder(
        $sourceBuilder,
        new SyncDestinationResolver(new StaticDestinationRegistry),
    );

    $config = $builder->build($sync->fresh());

    expect($builder->name($sync))
        ->toBe('ferry-sink-test-sync')
        ->and($config['connector.class'])
        ->toBe('io.confluent.connect.jdbc.JdbcSinkConnector')
        ->and($config['topics'])
        ->toBe($sourceBuilder->topic($connection, $object))
        ->and($config['table.name.format'])
        ->toBe($sync->destination)
        ->and($config['pk.mode'])
        ->toBe('record_key')
        ->and($config['insert.mode'])
        ->toBe('upsert')
        ->and($config['delete.enabled'])
        ->toBe('true')
        ->and($config['transforms.unwrap.delete.tombstone.handling.mode'])
        ->toBe('tombstone')
        ->and($config['auto.create'])
        ->toBe('false')
        ->and($config['connection.url'])
        ->toBe('jdbc:postgresql://db.internal:5432/:memory:')
        ->and($config['transforms.rename_keys.renames'])
        ->toBe('_idrref:idrref')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:idrref,_fld1:title')
        ->and($config['transforms'])
        ->toBe('unwrap,rename_keys,rename_values')
        ->and($config['transforms.unwrap.type'])
        ->toBe('io.debezium.transforms.ExtractNewRecordState')
        ->and($config['value.converter.schemas.enable'])
        ->toBe('true');
});

it('keeps each sink limited to the fields of its own mapping when several syncs share a source table', function () use (
    $makeObject,
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [
            $makeProperty('title', '_fld1', new StringField(length: 100, fixed: false)),
            $makeProperty('taxNumber', '_fld2', new StringField(length: 100, fixed: false)),
        ],
    ]);

    $first = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-a',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'title', 'column' => 'title'],
        ]),
    ]);

    $second = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-b',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'taxNumber', 'column' => 'tax_number'],
        ]),
    ]);

    $sourceBuilder = new SourceConnectorConfigBuilder;
    $builder = new SinkConnectorConfigBuilder(
        $sourceBuilder,
        new SyncDestinationResolver(new StaticDestinationRegistry),
    );

    $firstConfig = $builder->build($first->fresh());
    $secondConfig = $builder->build($second->fresh());

    expect($firstConfig['fields.whitelist'])
        ->toBe('idrref,title')
        ->and($secondConfig['fields.whitelist'])
        ->toBe('idrref,tax_number');
});

it('adds InsertField transforms and fixed columns for a static destination', function () use (
    $makeObject,
    $makeProperty,
) {
    $registry = app(StaticDestinationRegistry::class);
    $registry->register(new FakeCustomersDestination);

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => 'customers',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'title', 'column' => 'name'],
        ]),
    ]);

    $sourceBuilder = new SourceConnectorConfigBuilder;
    $builder = new SinkConnectorConfigBuilder($sourceBuilder, new SyncDestinationResolver($registry));

    $config = $builder->build($sync->fresh());

    expect($config['table.name.format'])
        ->toBe('customers')
        ->and($config['fields.whitelist'])
        ->toBe('customer_id,name,type,profile_id')
        ->and($config['transforms.rename_keys.renames'])
        ->toBe('_idrref:customer_id')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:customer_id,_fld1:name')
        ->and($config['transforms'])
        ->toBe('unwrap,rename_keys,rename_values,insert_type,insert_profile_id')
        ->and($config['transforms.insert_type.type'])
        ->toBe('org.apache.kafka.connect.transforms.InsertField$Value')
        ->and($config['transforms.insert_type.static.field'])
        ->toBe('type')
        ->and($config['transforms.insert_type.static.value'])
        ->toBe('Customer')
        ->and($config['transforms.insert_profile_id.static.field'])
        ->toBe('profile_id')
        ->and($config['transforms.insert_profile_id.static.value'])
        ->toBe('b2b-profile-id');
});

it('targets the static destination\'s own database connection', function () use ($makeObject, $makeProperty) {
    $registry = app(StaticDestinationRegistry::class);
    $registry->register(new FakeCustomersDestination('sales'));

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => 'customers',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'title', 'column' => 'name'],
        ]),
    ]);

    $sourceBuilder = new SourceConnectorConfigBuilder;
    $builder = new SinkConnectorConfigBuilder($sourceBuilder, new SyncDestinationResolver($registry));

    $config = $builder->build($sync->fresh());

    expect($config['connection.url'])
        ->toBe('jdbc:postgresql://sales.internal:5432/prozoo_sales')
        ->and($config['connection.user'])
        ->toBe('sales_user')
        ->and($config['connection.password'])
        ->toBe('sales_secret');
});

it('never propagates source deletions into a static destination', function () use ($makeObject, $makeProperty) {
    $registry = app(StaticDestinationRegistry::class);
    $registry->register(new FakeCustomersDestination);

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => 'customers',
        'source' => 'object-0',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => 'title', 'column' => 'name'],
        ]),
    ]);

    $builder = new SinkConnectorConfigBuilder(new SourceConnectorConfigBuilder, new SyncDestinationResolver($registry));

    $config = $builder->build($sync->fresh());

    expect($config['delete.enabled'])
        ->toBe('false')
        ->and($config['transforms.unwrap.delete.tombstone.handling.mode'])
        ->toBe('drop');
});

it('refuses to build a sink for an unregistered static destination', function () use ($makeObject) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $makeObject($connection);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
    ]);
    $sync->forceFill(['static_destination' => 'customers'])->saveQuietly();

    $builder = new SinkConnectorConfigBuilder(
        new SourceConnectorConfigBuilder,
        new SyncDestinationResolver(new StaticDestinationRegistry),
    );

    expect(fn () => $builder->build($sync->fresh()))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
            'destination' => 'customers',
        ]));
});
