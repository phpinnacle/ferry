<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Destinations\StaticDestination;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Origins\Kafka\JdbcConnectorConfig;
use PHPinnacle\Ferry\Origins\Kafka\SinkConfigFactory;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\ReplicaSchemaResolver;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Jason\Schema;
use PHPinnacle\Jason\SchemaBuilder;

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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld1', target: 'title'),
        ],
    ]);

    $builder = app(SourceFactory::class);
    config(['phpinnacle-ferry.replica.table_prefix' => 'changed_prefix_']);

    $config = new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
        new DestinationFactory(app(ReplicaSchemaResolver::class))->resolve($replica),
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
        ->and($config['transforms.rename_keys.renames'])
        ->toBe('_idrref:external_id')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_fld1:title')
        ->and($config['transforms.rename_values.include'])
        ->toBe('_fld1')
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
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld1', target: 'title'),
        ],
    ]);

    $second = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'replica-b',
        'source' => 'object-0',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: '_fld2', target: 'tax_number'),
        ],
    ]);

    $builder = app(SourceFactory::class);

    $firstConfig = new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
        new DestinationFactory(app(ReplicaSchemaResolver::class))->resolve($first),
        $first,
        $object,
        $builder->topic($upstream, $object),
    );
    $secondConfig = new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
        new DestinationFactory(app(ReplicaSchemaResolver::class))->resolve($second),
        $second,
        $object,
        $builder->topic($upstream, $object),
    );

    expect($firstConfig['fields.whitelist'])
        ->toBe('external_id,title')
        ->and($secondConfig['fields.whitelist'])
        ->toBe('external_id,tax_number');
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
        'mapping' => [
            new FieldMapping(source: '_idrref', target: 'customer_id'),
            new FieldMapping(source: '_fld1', target: 'name'),
        ],
    ]);

    $builder = app(SourceFactory::class);

    $config = new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
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
        ->toBe('record_key')
        ->and($config['pk.fields'])
        ->toBe('customer_id')
        ->and($config['transforms.rename_values.renames'])
        ->toBe('_fld1:name,__ferry_fixed_type:type,__ferry_fixed_profile_id:profile_id')
        ->and($config['transforms'])
        ->toBe('insert_type,insert_profile_id,rename_keys,rename_values')
        ->and($config['transforms.insert_type.type'])
        ->toBe('org.apache.kafka.connect.transforms.InsertField$Value')
        ->and($config['transforms.insert_type.static.field'])
        ->toBe('__ferry_fixed_type')
        ->and($config['transforms.insert_type.static.value'])
        ->toBe('Customer')
        ->and($config['transforms.insert_profile_id.static.field'])
        ->toBe('__ferry_fixed_profile_id')
        ->and($config['transforms.insert_profile_id.static.value'])
        ->toBe('b2b-profile-id')
        ->and($config)
        ->toMatchArray([
            'delete.enabled' => 'true',
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

it('refuses to resolve a static destination whose registration was removed', function () {
    $destinations = app(DestinationFactory::class);
    $destinations->register(customers_destination());
    $replica = TestCase::makeReplica([
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => [
            new FieldMapping('_idrref', 'customer_id'),
            FieldMapping::fromArray(['kind' => 'static', 'target' => 'name', 'static' => 'Customer', 'options' => []]),
            FieldMapping::fromArray(['kind' => 'static', 'target' => 'tax_number', 'static' => '123', 'options' => []]),
        ],
    ]);
    $withoutRegistration = new DestinationFactory(app(ReplicaSchemaResolver::class));

    expect(fn () => $withoutRegistration->resolve($replica))
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

    $builder = app(SourceFactory::class);

    expect(
        new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
            new DestinationFactory(app(ReplicaSchemaResolver::class))->resolve($replica),
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

it('selects and renames only source key fields mapped to the static primary key', function (
    array $bindings,
    array $primaryKey,
    array $sourceKey,
    array $expected,
) {
    $destination = new StaticDestination(
        key: 'explicit_keys',
        label: 'Explicit keys',
        table: 'explicit_keys',
        primaryKey: $primaryKey,
        schema: SchemaBuilder::make(Schema::object([
            'id' => SchemaBuilder::make()->type('string')->format('uuid')->with('x-ferry-reference', true)->build(),
            'source_id' => SchemaBuilder::make()
                ->type('string')
                ->format('uuid')
                ->with('x-ferry-reference', true)
                ->build(),
            '_owner' => SchemaBuilder::make()->type('string')->format('uuid')->with('x-ferry-reference', true)->build(),
        ]))
            ->required($primaryKey)
            ->build(),
    );
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'primary_key' => $sourceKey,
        'fields' => [
            '_owner' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => DestinationType::Static,
        'destination' => 'explicit_keys',
        'mapping' => array_map(
            fn (string $source, string $target) => new FieldMapping(source: $source, target: $target),
            array_keys($bindings),
            $bindings,
        ),
    ]);

    $config = new SinkConfigFactory($this->factory, app(ReplicaSchemaResolver::class))->make(
        $destination,
        $replica,
        $object,
        'topic',
    );

    expect($config)->toMatchArray([
        'pk.mode' => 'record_key',
        'transforms.rename_keys.type' => 'org.apache.kafka.connect.transforms.ReplaceField$Key',
        'delete.enabled' => 'true',
        ...$expected,
    ]);
})->with([
    'source identifier' => [
        ['_idrref' => 'id'],
        ['id'],
        ['_idrref'],
        [
            'pk.fields' => 'id',
            'fields.whitelist' => 'id',
            'transforms.rename_values.include' => '',
            'transforms.rename_values.exclude' => '_idrref,_owner',
            'transforms.rename_values.renames' => '',
            'transforms.rename_keys.include' => '_idrref',
            'transforms.rename_keys.renames' => '_idrref:id',
        ],
    ],
    'composite key' => [
        ['_idrref' => 'id', '_owner' => 'source_id'],
        ['source_id', 'id'],
        ['_idrref', '_owner'],
        [
            'pk.fields' => 'source_id,id',
            'fields.whitelist' => 'id,source_id',
            'transforms.rename_values.include' => '',
            'transforms.rename_values.exclude' => '_idrref,_owner',
            'transforms.rename_values.renames' => '',
            'transforms.rename_keys.include' => '_idrref,_owner',
            'transforms.rename_keys.renames' => '_idrref:id,_owner:source_id',
        ],
    ],
    'target named after an original key component' => [
        ['_owner' => 'source_id', '_idrref' => '_owner'],
        ['_owner', 'source_id'],
        ['_idrref', '_owner'],
        [
            'pk.fields' => '_owner,source_id',
            'fields.whitelist' => 'source_id,_owner',
            'transforms.rename_values.include' => '',
            'transforms.rename_values.exclude' => '_idrref,_owner',
            'transforms.rename_values.renames' => '',
            'transforms.rename_keys.include' => '_idrref,_owner',
            'transforms.rename_keys.renames' => '_idrref:_owner,_owner:source_id',
        ],
    ],
]);

it('empties key-only sink values after record transforms without discarding the record key', function (
    DestinationType $type,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => ['name' => ['type' => 'string'], 'shared' => ['type' => 'string']],
    ]);
    $destination = new StaticDestination(
        'key_only',
        'Key only',
        'key_only',
        ['external_id'],
        SchemaBuilder::make(Schema::object(['external_id' => Schema::fromArray(TestCase::keySchema()->toArray())]))
            ->required(['external_id'])
            ->build(),
    );
    app(DestinationFactory::class)->register($destination);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'type' => $type,
        'destination' => $type === DestinationType::Static ? 'key_only' : null,
        'transforms' => [new TransformSetting('slug', ['input' => "$['name']", 'output' => 'slug'])],
    ]);

    $config = app(SinkConfigFactory::class)->make(
        app(DestinationFactory::class)->resolve($replica),
        $replica,
        $object,
        'topic',
    );

    expect($config)->toMatchArray([
        'transforms' => 'replica_0,rename_keys,rename_values',
        'transforms.replica_0.input' => 'name',
        'transforms.rename_keys.include' => '_idrref',
        'transforms.rename_keys.renames' => '_idrref:external_id',
        'transforms.rename_values.include' => '',
        'transforms.rename_values.exclude' => '_idrref,name,slug,shared',
        'transforms.rename_values.renames' => '',
        'pk.mode' => 'record_key',
        'pk.fields' => 'external_id',
        'delete.enabled' => 'true',
    ]);
})->with([DestinationType::Dynamic, DestinationType::Static]);
