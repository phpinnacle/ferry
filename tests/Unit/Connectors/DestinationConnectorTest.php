<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\SourceFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\StringField;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../../TestCase.php';
require_once __DIR__ . '/../../Fakes/CustomersDestination.php';

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

$makeProperty = fn (string $id, string $name, object $field) => new MetadataProperty(
    id: $id,
    name: $name,
    code: 2,
    kind: PropertyKind::Field,
    label: ucfirst($id),
    title: ucfirst($id),
    field: $field,
);

it('builds a confluent jdbc sink connector config with field renames', function () use ($makeProperty) {
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
    ]);

    $builder = new SourceFactory;
    config(['phpinnacle-ferry.sync.table_prefix' => 'changed_prefix_']);

    $config = new DestinationFactory()
        ->resolve($sync)
        ->connector(
            $sync,
            $object,
            $builder->topic($connection, $object),
        );

    expect($builder->name($sync))
        ->toBe('ferry-sink-test-sync')
        ->and($config['connector.class'])
        ->toBe('io.confluent.connect.jdbc.JdbcSinkConnector')
        ->and($config['topics'])
        ->toBe($builder->topic($connection, $object))
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
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [
            $makeProperty('title', '_fld1', new StringField(length: 100, fixed: false)),
            $makeProperty('taxNumber', '_fld2', new StringField(length: 100, fixed: false)),
        ],
    ]);

    $first = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-a',
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($object, [
            'title' => 'title',
        ]),
    ]);

    $second = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'sync-b',
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($object, [
            'taxNumber' => 'tax_number',
        ]),
    ]);

    $builder = new SourceFactory;

    $firstConfig = new DestinationFactory()
        ->resolve($first)
        ->connector(
            $first,
            $object,
            $builder->topic($connection, $object),
        );
    $secondConfig = new DestinationFactory()
        ->resolve($second)
        ->connector(
            $second,
            $object,
            $builder->topic($connection, $object),
        );

    expect($firstConfig['fields.whitelist'])
        ->toBe('idrref,title')
        ->and($secondConfig['fields.whitelist'])
        ->toBe('idrref,tax_number');
});

it('builds a static sink with fixed fields and its target connection', function (
    ?string $connectionName,
    array $target,
) use ($makeProperty) {
    $registry = app(DestinationFactory::class);
    $registry->register(customers_destination($connectionName));

    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('title', '_fld1', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'static_destination' => 'customers',
        'source' => 'object-0',
        'schema' => SyncSchema::fromBindings($object, [
            'title' => 'name',
        ]),
    ]);

    $builder = new SourceFactory;

    $config = $registry->resolve($sync)->connector($sync, $object, $builder->topic($connection, $object));

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
        ->toBe('b2b-profile-id')
        ->and($config)
        ->toMatchArray([
            'delete.enabled' => 'false',
            'transforms.unwrap.delete.tombstone.handling.mode' => 'drop',
            ...$target,
        ]);
})->with([
    'default Ferry connection' => [
        null,
        [
            'connection.url' => 'jdbc:postgresql://db.internal:5432/:memory:',
            'connection.user' => '',
            'connection.password' => '',
        ],
    ],
    'application connection' => [
        'sales',
        [
            'connection.url' => 'jdbc:postgresql://sales.internal:5432/prozoo_sales',
            'connection.user' => 'sales_user',
            'connection.password' => 'sales_secret',
        ],
    ],
]);

it('refuses to build a sink for an unregistered static destination', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
    ]);
    $sync->forceFill(['static_destination' => 'customers'])->saveQuietly();

    expect(fn () => new DestinationFactory()->resolve($sync))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.sync.errors.static_destination_missing', [
            'destination' => 'customers',
        ]));
});

it('keeps configured sink hosts and environment ports while applying the default port when absent', function (
    ?string $host,
    int|string|null $port,
    string $expectedHost,
    int $expectedPort,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection);
    $sync = TestCase::makeSync(['connection_id' => $connection->id]);
    config(['phpinnacle-ferry.target_host' => $host]);

    if ($port === null) {
        $target = config('database.connections.sqlite');
        unset($target['port']);
        config(['database.connections.sqlite' => $target]);
    } else {
        config(['database.connections.sqlite.port' => $port]);
    }

    $builder = new SourceFactory;

    expect(
        new DestinationFactory()
            ->resolve($sync)
            ->connector(
                $sync,
                $object,
                $builder->topic($connection, $object),
            )['connection.url'],
    )
        ->toBe(sprintf('jdbc:postgresql://%s:%d/:memory:', $expectedHost, $expectedPort));
})->with([
    'environment port' => [null, '5544', 'db.internal', 5544],
    'host override' => ['db.kafka.internal', 5544, 'db.kafka.internal', 5544],
    'empty host override' => ['', 5432, 'db.internal', 5432],
    'default port' => [null, null, 'db.internal', 5432],
]);
