<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\JdbcConnectorConfig;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../../../TestCase.php';
require_once __DIR__ . '/../../../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();

    $this->factory = $this->app->make(TransformFactory::class);

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

it('passes ready transform configuration through without adding replica transforms', function () {
    $transforms = [
        'transforms' => 'custom',
        'transforms.custom.type' => 'example.CustomTransform',
        'transforms.custom.predicate' => 'custom_predicate',
        'predicates' => 'custom_predicate',
        'predicates.custom_predicate.type' => 'example.CustomPredicate',
    ];
    $config = new JdbcConnectorConfig(
        table: 'customers',
        topic: 'customers',
        columns: ['id', 'name'],
        transforms: $transforms,
    )->toArray();

    expect(array_intersect_key($config, $transforms))
        ->toBe($transforms)
        ->and($config['fields.whitelist'])
        ->toBe('id,name')
        ->and($config)
        ->not->toHaveKeys(['transforms.unwrap.type', 'transforms.rename_keys.type', 'transforms.rename_values.type']);
});

it('builds a confluent jdbc sink connector config with field renames', function () {
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
    ]);

    $builder = new SourceFactory;
    config(['phpinnacle-ferry.replica.table_prefix' => 'changed_prefix_']);

    $config = new SinkConfigFactory($this->factory)->make(
        new DestinationFactory()->resolve($replica),
        $replica,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($builder->name($replica))
        ->toBe('ferry-sink-test-replica')
        ->and($config['connector.class'])
        ->toBe('io.confluent.connect.jdbc.JdbcSinkConnector')
        ->and($config['topics'])
        ->toBe($builder->topic($upstream, $object))
        ->and($config['table.name.format'])
        ->toBe($replica->destination)
        ->and($config['pk.mode'])
        ->toBe('record_key')
        ->and($config['insert.mode'])
        ->toBe('upsert')
        ->and($config['delete.enabled'])
        ->toBe('true')
        ->and($config['auto.create'])
        ->toBe('false')
        ->and($config['connection.url'])
        ->toBe('jdbc:postgresql://db.internal:5432/:memory:')
        ->and($config['transforms.rename_keys.renames'])
        ->toBe('_idrref:idrref')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:idrref,_fld1:title')
        ->and($config['transforms'])
        ->toBe('rename_keys,rename_values')
        ->and($config)
        ->not->toHaveKey('transforms.unwrap.type');
});

it('keeps each sink limited to the fields of its own mapping when several replicas share a source table', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => [
            '_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title'],
            '_fld2' => ['type' => 'string', 'maxLength' => 100, 'title' => 'TaxNumber'],
        ],
    ]);

    $first = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-a',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_fld1', 'title', $object->field('_fld1')),
        ],
    ]);

    $second = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-b',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_fld2', 'tax_number', $object->field('_fld2')),
        ],
    ]);

    $builder = new SourceFactory;

    $firstConfig = new SinkConfigFactory($this->factory)->make(
        new DestinationFactory()->resolve($first),
        $first,
        $object,
        $builder->topic($upstream, $object),
    );
    $secondConfig = new SinkConfigFactory($this->factory)->make(
        new DestinationFactory()->resolve($second),
        $second,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($firstConfig['fields.whitelist'])
        ->toBe('idrref,title')
        ->and($secondConfig['fields.whitelist'])
        ->toBe('idrref,tax_number');
});

it('builds a static sink with fixed fields and its target upstream', function (
    ?string $upstreamName,
    array $target,
) {
    $registry = app(DestinationFactory::class);
    $registry->register(customers_destination($upstreamName));

    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => ['_fld1' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_idrref', 'customer_id', $object->field('_idrref')),
            new FieldMapping('_fld1', 'name', $object->field('_fld1')),
        ],
    ]);

    $builder = new SourceFactory;

    $config = new SinkConfigFactory($this->factory)->make(
        $registry->resolve($replica),
        $replica,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($config['table.name.format'])
        ->toBe('customers')
        ->and($config['fields.whitelist'])
        ->toBe('customer_id,name,type,profile_id')
        ->and($config['pk.mode'])
        ->toBe('record_value')
        ->and($config['pk.fields'])
        ->toBe('customer_id')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_idrref:customer_id,_fld1:name')
        ->and($config['transforms'])
        ->toBe('drop_tombstones,rename_values,insert_type,insert_profile_id')
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
            'transforms.drop_tombstones.type' => 'org.apache.kafka.connect.transforms.Filter',
            'transforms.drop_tombstones.predicate' => 'tombstone',
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
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'source' => 'object-0',
    ]);
    $replica->forceFill(['type' => DestinationType::Static, 'destination' => 'customers'])->saveQuietly();

    expect(fn () => new DestinationFactory()->resolve($replica))
        ->toThrow(LogicException::class, __('phpinnacle-ferry::resources.replica.errors.static_destination_missing', [
            'destination' => 'customers',
        ]));
});

it('keeps configured sink hosts and environment ports while applying the default port when absent', function (
    ?string $host,
    int|string|null $port,
    string $expectedHost,
    int $expectedPort,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id]);

    if ($port === null) {
        $target = config('database.connections.sqlite');
        unset($target['port']);
        config(['database.connections.sqlite' => $target]);
    } else {
        config(['database.connections.sqlite.port' => $port]);
    }

    $builder = new SourceFactory;

    expect(
        new SinkConfigFactory($this->factory)->make(
            new DestinationFactory()->resolve($replica),
            $replica,
            $object,
            $builder->topic($upstream, $object),
            targetHost: $host,
        )['connection.url'],
    )
        ->toBe(sprintf('jdbc:postgresql://%s:%d/:memory:', $expectedHost, $expectedPort));
})->with([
    'environment port' => [null, '5544', 'db.internal', 5544],
    'host override' => ['db.kafka.internal', 5544, 'db.kafka.internal', 5544],
    'empty host override' => ['', 5432, 'db.internal', 5432],
    'default port' => [null, null, 'db.internal', 5432],
]);

it('uses the explicitly mapped record value as the static primary key', function (
    array $bindings,
    string $renames,
    string $whitelist,
    array $primaryKey,
    string $keyFields,
) {
    $destination = new StaticDestination(
        key: 'explicit_keys',
        label: 'Explicit keys',
        table: 'explicit_keys',
        primaryKey: $primaryKey,
        schema: Schema::object([
            'id' => Schema::string()->format('uuid')->with('x-ferry-reference', true),
            'source_id' => Schema::string()->format('uuid')->with('x-ferry-reference', true),
        ])->required(['id']),
    );
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, ['fields' => [
        '_idrref' => JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
        '_owner' => JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]),
    ]]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'explicit_keys',
        'schema' => array_map(
            fn (string $source, string $target) => new FieldMapping($source, $target, $object->field($source)),
            array_keys($bindings),
            $bindings,
        ),
    ]);

    $config = new SinkConfigFactory($this->factory)->make($destination, $replica, $object, 'topic');

    expect($config)->toMatchArray([
        'pk.mode' => 'record_value',
        'pk.fields' => $keyFields,
        'fields.whitelist' => $whitelist,
        'transforms.rename_values.renames' => $renames,
        'delete.enabled' => 'false',
    ]);
    expect($config)->not->toHaveKey('transforms.rename_keys.type');
})->with([
    'source identifier' => [['_idrref' => 'id'], '_idrref:id', 'id', ['id'], 'id'],
    'another reference, with the source identifier mapped separately' => [
        ['_owner' => 'id', '_idrref' => 'source_id'],
        '_owner:id,_idrref:source_id',
        'id,source_id',
        ['id'],
        'id',
    ],
    'composite key' => [
        ['_idrref' => 'id', '_owner' => 'source_id'],
        '_idrref:id,_owner:source_id',
        'id,source_id',
        ['source_id', 'id'],
        'source_id,id',
    ],
]);
