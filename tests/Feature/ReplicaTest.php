<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\FieldKind;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Services\ReplicaTableManager;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\Fakes\Transform as FakeTransform;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformations\MaskField;

require_once __DIR__ . '/../Fakes/Transform.php';

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('stores and serializes ordered record transforms', function () {
    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream, ['sources' => ['name' => ['type' => 'string']]]);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id, 'mapping' => []]);
    expect($replica->fresh()->transforms)->toBe([]);

    $settings = [
        new TransformSetting('insert_uuid', ['fields' => ["$['_idrref']"], 'output' => 'uuid']),
        new TransformSetting('slug', ['input' => "$['name']", 'output' => 'slug']),
    ];
    $replica->update(['transforms' => $settings]);
    $saved = $replica->fresh();
    $serialized = [
        ['type' => 'insert_uuid', 'data' => ['fields' => ["$['_idrref']"], 'output' => 'uuid']],
        ['type' => 'slug', 'data' => ['input' => "$['name']", 'output' => 'slug']],
    ];

    expect($saved->transforms)
        ->toEqual($settings)
        ->and($saved->toArray()['transforms'])
        ->toBe($serialized)
        ->and(json_decode($saved->getRawOriginal('transforms'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($serialized);
});

it('rolls back a replica and its table when creation fails', function () {
    $tables = Mockery::mock(ReplicaTableManager::class);
    $tables
        ->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (Replica $replica) {
            $replica
                ->getConnection()
                ->getSchemaBuilder()
                ->create($replica->destination, function (Blueprint $table) {
                    $table->string('external_id')->primary();
                });

            throw new RuntimeException('Destination creation failed');
        });
    $this->app->instance(ReplicaTableManager::class, $tables);

    expect(TestCase::makeReplica(...))->toThrow(RuntimeException::class, 'Destination creation failed');

    expect(Replica::query()->count())->toBe(0)->and(Schema::hasTable('ferry_replica_test-replica'))->toBeFalse();
});

it('finds source objects only in the upstream published root metadata', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $published = TestCase::makeItem($upstream);
    TestCase::makeItem($upstream, ['revision' => 2]);
    TestCase::makeItem($upstream, ['external_id' => 'draft-only', 'revision' => 2]);
    TestCase::makeItem($upstream, ['external_id' => 'child', 'parent_id' => $published->id]);

    $otherUpstream = TestCase::makeUpstream(
        ['code' => 'other-upstream'],
        ['status' => StructureStatus::Ready, 'generation' => 1],
    );
    $other = TestCase::makeItem($otherUpstream);
    TestCase::makeItem($otherUpstream, ['external_id' => 'other-only']);

    expect($upstream->structure->publishedItem('object-0')?->is($published))
        ->toBeTrue()
        ->and($otherUpstream->structure->publishedItem('object-0')?->is($other))
        ->toBeTrue()
        ->and($upstream->structure->publishedItem('draft-only'))
        ->toBeNull()
        ->and($upstream->structure->publishedItem('child'))
        ->toBeNull()
        ->and($upstream->structure->publishedItem('other-only'))
        ->toBeNull()
        ->and($upstream->structure->publishedItem('missing'))
        ->toBeNull();
});

it('owns the persisted schema and destination table throughout its lifecycle', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => [
            'title' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title'],
            'price' => [
                'type' => 'number',
                'x-ferry-precision' => 15,
                'x-ferry-scale' => 2,
                'multipleOf' => 0.01,
                'exclusiveMinimum' => -10_000_000_000_000,
                'exclusiveMaximum' => 10_000_000_000_000,
                'title' => 'Price',
            ],
            'posted' => ['type' => 'boolean', 'title' => 'Posted'],
        ],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'mapping' => [
            new FieldMapping(source: '_idrref', target: 'external_id'),
            new FieldMapping(source: 'title', target: 'title'),
            new FieldMapping(source: 'price', target: 'price'),
            new FieldMapping(source: 'posted', target: 'posted'),
        ],
    ]);

    expect($replica->status)
        ->toBe(ReplicaStatus::Pending)
        ->and($replica->destination)
        ->toBe('ferry_replica_test-replica')
        ->and($replica->fresh()->mapping)
        ->toEqual($replica->mapping)
        ->and($upstream->isInUse())
        ->toBeTrue()
        ->and(Schema::getColumnListing($replica->destination))
        ->toContain('external_id', 'title', 'price', 'posted')
        ->not->toContain('id', 'created_at', 'updated_at');

    $replica->delete();

    app(\PHPinnacle\Ferry\Services\ConnectorManager::class)->synchronize($upstream->id);

    expect(Schema::hasTable($replica->destination))
        ->toBeFalse()
        ->and(Replica::query()->count())
        ->toBe(0)
        ->and($upstream->isInUse())
        ->toBeFalse();
});

it('adds, renames and drops columns when the mapping changes', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => [
            'title' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title'],
            'price' => [
                'type' => 'number',
                'x-ferry-precision' => 15,
                'x-ferry-scale' => 2,
                'multipleOf' => 0.01,
                'exclusiveMinimum' => -10_000_000_000_000,
                'exclusiveMaximum' => 10_000_000_000_000,
                'title' => 'Price',
            ],
        ],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'mapping' => [
            new FieldMapping(source: '_idrref', target: 'external_id'),
            new FieldMapping(source: 'title', target: 'title'),
        ],
    ]);

    DB::table($replica->destination)->insert([
        'external_id' => 'source-1',
        'title' => 'Original title',
    ]);

    $updated = [
        new FieldMapping(source: '_idrref', target: 'row_id'),
        new FieldMapping(source: 'price', target: 'price'),
    ];

    $replica->fill(['mapping' => $updated]);
    expect(app(ReplicaTableManager::class)->mappingChanges($replica)['drop'])->toBe(['title']);
    $replica->save();

    expect(DB::table($replica->destination)->sole())->toEqual((object) [
        'row_id' => 'source-1',
        'price' => null,
    ]);
    expect(Schema::getColumnListing($replica->destination))
        ->toContain('row_id', 'price')
        ->not->toContain('external_id', 'title');
});

it('preserves renamed values when a new mapping reuses the original column name', function () {
    $field = ['type' => 'string', 'maxLength' => 100];
    $replica = TestCase::makeReplica([
        'schema' => JsonSchema::fromArray([
            'properties' => ['_idrref' => TestCase::keySchema()->toArray(), 'name' => $field, 'alias' => $field],
            'x-primary-key' => ['_idrref'],
        ]),
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: 'name', target: 'name'),
        ],
    ]);
    DB::table($replica->destination)->insert(['external_id' => 'row-1', 'name' => 'Original']);

    $replica->fill(['mapping' => [
        TestCase::keyMapping(),
        new FieldMapping(source: 'name', target: 'display_name'),
        new FieldMapping(source: 'alias', target: 'name'),
    ]]);

    expect(app(ReplicaTableManager::class)->mappingChanges($replica)['drop'])->toBe([]);
    $replica->save();

    expect(DB::table($replica->destination)->sole())->toEqual((object) [
        'external_id' => 'row-1',
        'display_name' => 'Original',
        'name' => null,
    ]);
});

it('uses output schema bounds for PostgreSQL varchar columns', function () {
    app(TransformRegistry::class)->register(MaskField::make(), new FakeTransform('bounded', output: fn () => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 30,
    ])));
    $object = StructureItems::make([
        'fields' => [
            '_idrref' => TestCase::keySchema(),
            'bounded' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 10]),
            'unbounded' => JsonSchema::fromArray(['type' => 'string']),
            'zero' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 0]),
            'masked' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1]),
            'expanded' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1]),
        ],
        'sources' => [],
    ]);
    $mappings = [
        new FieldMapping(source: 'bounded', target: 'bounded'),
        new FieldMapping(source: 'unbounded', target: 'unbounded'),
        new FieldMapping(source: 'zero', target: 'zero'),
        new FieldMapping(source: 'masked', target: 'masked', options: [
            'transforms' => [['type' => 'mask', 'data' => ['replacement' => '***']]],
        ]),
        new FieldMapping(source: 'expanded', target: 'expanded', options: [
            'transforms' => [['type' => 'bounded', 'data' => []]],
        ]),
        new FieldMapping(source: null, target: 'constant', static: 'value', kind: FieldKind::Static),
    ];
    $replica = Mockery::mock(Replica::class)->makePartial();
    $replica->fill(['schema' => $object->schema, 'mapping' => [TestCase::keyMapping(), ...$mappings]]);
    $replica
        ->shouldReceive('sourceObject')
        ->andReturn(StructureItems::make(['fields' => ['_idrref' => TestCase::keySchema()]]));
    $replica->destination = 'string_outputs';
    config(['phpinnacle-ferry.connection' => 'pgsql']);

    $queries = DB::connection('pgsql')->pretend(fn () => app(ReplicaTableManager::class)->create($replica));

    expect($queries[0]['query'])
        ->toContain('"bounded" varchar(10) null', '"expanded" varchar(30) null')
        ->toContain(
            '"unbounded" varchar null',
            '"zero" varchar null',
            '"masked" varchar null',
            '"constant" varchar null',
        );
});

it('alters string bounds without dropping stored values, including when renaming', function () {
    app(TransformRegistry::class)->register(MaskField::make(), new FakeTransform('bounded', output: fn () => JsonSchema::fromArray([
        'type' => 'string',
        'maxLength' => 30,
    ])));
    $object = StructureItems::make([
        'fields' => [
            '_idrref' => TestCase::keySchema(),
            'code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1]),
        ],
        'sources' => [],
    ]);
    $replica = TestCase::makeReplica([
        'schema' => $object->schema,
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: 'code', target: 'code'),
        ],
    ]);
    DB::table($replica->destination)->insert(['external_id' => 'row-1', 'code' => 'A']);

    foreach (['mask' => null, 'bounded' => 30] as $key => $length) {
        $replica->fill(['mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(source: 'code', target: 'display_code', options: [
                'transforms' => [['type' => $key, 'data' => $key === 'mask' ? ['replacement' => '***'] : []]],
            ]),
        ]]);
        expect(app(ReplicaTableManager::class)->mappingChanges($replica)['drop'])->toBe([]);
        config(['phpinnacle-ferry.connection' => 'pgsql']);
        $queries = DB::connection('pgsql')->pretend(fn () => app(ReplicaTableManager::class)->update($replica));
        config(['phpinnacle-ferry.connection' => null]);
        $sql = implode("\n", array_column($queries, 'query'));
        expect($sql)
            ->toContain('alter column "display_code" type varchar' . ($length === null ? ',' : '(30),'))
            ->not->toContain('drop column', 'using');
        $replica->save();
        expect(DB::table($replica->destination)->value('display_code'))->toBe('A');
    }
});

it('changes the column type when a mapped field changes type', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => ['flag' => ['type' => 'boolean', 'title' => 'Flag']],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'code' => 'type_change',
        'mapping' => [
            new FieldMapping(source: '_idrref', target: 'external_id'),
            new FieldMapping(source: 'flag', target: 'flag'),
        ],
    ]);

    $schema = $replica->schema->toArray();
    $schema['properties']['flag'] = ['type' => 'number', 'x-ferry-precision' => 15, 'x-ferry-scale' => 2];
    $replica->update(['schema' => JsonSchema::fromArray($schema)]);

    $column = collect(DB::select('PRAGMA table_info("' . $replica->destination . '")'))
        ->firstWhere('name', 'flag');

    expect($column->type)->toBe('numeric');
});

it('generates its dynamic table name on creation and preserves it after prefix and code changes', function () {
    config(['phpinnacle-ferry.replica.table_prefix' => 'initial_']);
    $replica = TestCase::makeReplica(['code' => 'orders', 'destination' => 'ignored_table']);
    DB::table('initial_orders')->insert(['external_id' => 'source-1']);

    expect($replica->fresh()->destination)->toBe('initial_orders');
    expect(Schema::hasTable('ignored_table'))->toBeFalse();

    config(['phpinnacle-ferry.replica.table_prefix' => 'changed_']);
    $replica->update(['code' => 'renamed']);

    expect($replica->fresh()->destination)->toBe('initial_orders');
    expect(DB::table('initial_orders')->value('external_id'))->toBe('source-1');
    expect(Schema::hasTable('changed_renamed'))->toBeFalse();
});

it('creates decimal columns from the effective output schema', function (?array $result, string $sql) {
    config([
        'phpinnacle-ferry.connection' => 'schema-preview',
        'database.connections.schema-preview' => ['driver' => 'pgsql', 'database' => 'preview'],
    ]);
    $replica = Mockery::mock(Replica::class)->makePartial();
    $replica
        ->shouldReceive('sourceObject')
        ->andReturn(StructureItems::make(['fields' => ['_idrref' => TestCase::keySchema()]]));
    if ($result !== null) {
        app(TransformRegistry::class)->register(new FakeTransform('decimal', output: fn () => JsonSchema::fromArray(
            $result,
        )));
    }

    $replica->fill([
        'schema' => JsonSchema::fromArray([
            'properties' => [
                '_idrref' => TestCase::keySchema()->toArray(),
                'amount' => ['type' => 'number', 'x-ferry-precision' => 12, 'x-ferry-scale' => 4],
            ],
            'x-primary-key' => ['_idrref'],
        ]),
        'destination' => 'decimal_columns',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping(
                'amount',
                'amount',
                $result === null ? [] : ['transforms' => [['type' => 'decimal', 'data' => []]]],
            ),
        ],
    ]);

    $queries = $replica->getConnection()->pretend(fn () => app(ReplicaTableManager::class)->create($replica));

    expect($queries[0]['query'])->toContain($sql);
})->with([
    'untransformed source' => [null, '"amount" decimal(12, 4) null'],
    'transformed output' => [
        ['type' => 'number', 'x-ferry-precision' => 8, 'x-ferry-scale' => 1],
        '"amount" decimal(8, 1) null',
    ],
    'cast without numeric bounds' => [['type' => 'number'], '"amount" decimal(15, 2) null'],
]);

it('creates SQL columns from timestamp conversion output', function (string $target, string $sql) {
    config([
        'phpinnacle-ferry.connection' => 'timestamp-preview',
        'database.connections.timestamp-preview' => ['driver' => 'pgsql', 'database' => 'preview'],
    ]);
    $object = StructureItems::make(['fields' => [
        '_idrref' => TestCase::keySchema(),
        'created' => JsonSchema::fromArray(['type' => 'integer']),
    ]]);
    $replica = Mockery::mock(Replica::class)->makePartial();
    $replica->shouldReceive('sourceObject')->andReturn($object);
    $replica->fill([
        'schema' => $object->schema,
        'destination' => 'timestamp_columns',
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping('created', 'created', [
                'transforms' => [[
                    'type' => 'timestamp_converter',
                    'data' => ['target_type' => $target, 'format' => 'yyyy-MM-dd'],
                ]],
            ]),
        ],
    ]);

    $queries = $replica->getConnection()->pretend(fn () => app(ReplicaTableManager::class)->create($replica));

    expect($queries[0]['query'])->toContain($sql);
})->with([
    'date' => ['Date', '"created" date null'],
    'time' => ['Time', '"created" time(0) without time zone null'],
    'timestamp' => ['Timestamp', '"created" timestamp(0) without time zone null'],
    'epoch' => ['unix', '"created" bigint null'],
    'text' => ['string', '"created" varchar null'],
]);

it('copies the complete input schema on creation and keeps its own snapshot after source changes', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeItem($upstream, [
        'sources' => [
            'title' => [
                'type' => 'string',
                'title' => 'Title',
                'description' => 'Original description',
                'maxLength' => 40,
            ],
        ],
    ]);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id, 'mapping' => []]);
    $snapshot = $object->schema->toArray();
    expect($replica->fresh()->schema->toArray())->toBe($snapshot);

    $changed = $snapshot;
    $changed['properties']['title']['maxLength'] = 80;
    $object->update(['schema' => JsonSchema::fromArray($changed)]);
    expect($replica->fresh()->schema->toArray())->toBe($snapshot);

    $replica->update(['schema' => JsonSchema::fromArray($changed)]);
    expect($replica->fresh()->schema->toArray())
        ->toBe($changed)
        ->and($replica->fresh()->mapping)
        ->toBe([])
        ->and(Schema::hasTable($replica->destination))
        ->toBeFalse();
});
it('applies combined removals, type replacements, renames and additions without losing retained values', function () {
    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream, [
        'sources' => [
            'removed_first' => ['type' => 'string'],
            'flag' => ['type' => 'boolean'],
            'name' => ['type' => 'string', 'maxLength' => 10],
            'removed_last' => ['type' => 'string'],
            'added' => ['type' => 'integer'],
        ],
    ]);
    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping('removed_first', 'removed_first'),
            new FieldMapping('flag', 'flag'),
            new FieldMapping('name', 'name'),
            new FieldMapping('removed_last', 'removed_last'),
        ],
    ]);
    DB::table($replica->destination)->insert([
        'external_id' => 'row-1',
        'removed_first' => 'old',
        'flag' => true,
        'name' => 'Retained',
        'removed_last' => 'old',
    ]);
    $schema = $replica->schema->toArray();
    $schema['properties']['flag'] = ['type' => 'integer'];
    $schema['properties']['name']['maxLength'] = 20;

    $replica->update([
        'schema' => JsonSchema::fromArray($schema),
        'mapping' => [
            TestCase::keyMapping(),
            new FieldMapping('name', 'display_name'),
            new FieldMapping('added', 'added'),
            new FieldMapping('flag', 'flag'),
        ],
    ]);

    expect(DB::table($replica->destination)->sole())->toEqual((object) [
        'external_id' => 'row-1',
        'display_name' => 'Retained',
        'added' => null,
        'flag' => null,
    ]);
    expect(Schema::getColumnListing($replica->destination))->toEqualCanonicalizing([
        'external_id',
        'display_name',
        'added',
        'flag',
    ]);
    DB::table($replica->destination)
        ->where('external_id', 'row-1')
        ->update(['added' => 7, 'flag' => 2, 'display_name' => 'A longer name']);
    expect(DB::table($replica->destination)->value('flag'))->toBe(2);
    expect(
        collect($replica->getConnection()->getSchemaBuilder()->getColumns($replica->destination))
            ->firstWhere('name', 'flag')['type_name'],
    )
        ->toBe('integer');
});
