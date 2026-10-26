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
use PHPinnacle\Ferry\Origins\Kafka\TransformFactory;
use PHPinnacle\Ferry\Services\ReplicaTableManager;
use PHPinnacle\Ferry\Tests\Fixtures\StructureItems;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('stores ordered record transforms without applying them', function () {
    $replica = TestCase::makeReplica(['schema' => []]);
    expect($replica->fresh()->transforms)->toBe([]);

    $settings = [
        new TransformSetting('future_first', ['field' => '_description']),
        new TransformSetting('future_second', ['value' => 'example']),
    ];
    $replica->update(['transforms' => $settings]);
    $saved = $replica->fresh();
    $serialized = array_map(fn (TransformSetting $setting) => $setting->jsonSerialize(), $settings);

    expect($saved->transforms)
        ->toEqual($settings)
        ->and($saved->toArray()['transforms'])
        ->toBe($serialized)
        ->and(json_decode($saved->getRawOriginal('transforms'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($serialized)
        ->and(app(TransformFactory::class)->pipeline($saved, StructureItems::make())['transforms'])
        ->toBe('unwrap,rename_keys,rename_values');
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
                    $table->string(Replica::ID_COLUMN)->primary();
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
        'schema' => [
            new FieldMapping('_idrref', 'external_id', $object->field('_idrref')),
            new FieldMapping('title', 'title', $object->field('title')),
            new FieldMapping('price', 'price', $object->field('price')),
            new FieldMapping('posted', 'posted', $object->field('posted')),
        ],
    ]);

    expect($replica->status)
        ->toBe(ReplicaStatus::Pending)
        ->and($replica->destination)
        ->toBe('ferry_replica_test-replica')
        ->and($replica->fresh()->schema)
        ->toEqual($replica->schema)
        ->and($upstream->isInUse())
        ->toBeTrue()
        ->and(Schema::getColumnListing($replica->destination))
        ->toContain(Replica::ID_COLUMN, 'external_id', 'title', 'price', 'posted')
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
        'schema' => [
            new FieldMapping('_idrref', 'external_id', $object->field('_idrref')),
            new FieldMapping('title', 'title', $object->field('title')),
        ],
    ]);

    DB::table($replica->destination)->insert([
        Replica::ID_COLUMN => 'row-1',
        'external_id' => 'source-1',
        'title' => 'Original title',
    ]);

    $updated = [
        new FieldMapping('_idrref', 'row_id', $object->field('_idrref')),
        new FieldMapping('price', 'price', $object->field('price')),
    ];

    $replica->fill(['schema' => $updated]);
    expect($replica->droppedColumns())->toBe(['title']);
    $replica->save();

    expect(DB::table($replica->destination)->sole())->toEqual((object) [
        Replica::ID_COLUMN => 'row-1',
        'row_id' => 'source-1',
        'price' => null,
    ]);
    expect(Schema::getColumnListing($replica->destination))
        ->toContain('row_id', 'price')
        ->not->toContain('external_id', 'title');
});

it('preserves renamed values when a new mapping reuses the original column name', function () {
    $field = JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100]);
    $replica = TestCase::makeReplica(['schema' => [new FieldMapping('name', 'name', $field)]]);
    DB::table($replica->destination)->insert([Replica::ID_COLUMN => 'row-1', 'name' => 'Original']);

    $replica->fill(['schema' => [
        new FieldMapping('name', 'display_name', $field),
        new FieldMapping('alias', 'name', $field),
    ]]);

    expect($replica->droppedColumns())->toBe([]);
    $replica->save();

    expect(DB::table($replica->destination)->sole())->toEqual((object) [
        Replica::ID_COLUMN => 'row-1',
        'display_name' => 'Original',
        'name' => null,
    ]);
});

it('uses output schema bounds for PostgreSQL varchar columns', function () {
    $object = StructureItems::make([
        'fields' => [
            'bounded' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 10]),
            'unbounded' => JsonSchema::fromArray(['type' => 'string']),
            'zero' => JsonSchema::fromArray(['type' => 'string']),
            'masked' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1]),
            'expanded' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1]),
        ],
        'sources' => [],
    ]);
    $mappings = [
        new FieldMapping('bounded', 'bounded', $object->field('bounded')),
        new FieldMapping('unbounded', 'unbounded', $object->field('unbounded')),
        new FieldMapping('zero', 'zero', $object->field('zero')),
        new FieldMapping(
            'masked',
            'masked',
            $object->field('masked'),
            result: JsonSchema::fromArray(['type' => 'string']),
            transforms: [new TransformSetting('mask', ['replacement' => '***'])],
        ),
        new FieldMapping(
            'expanded',
            'expanded',
            $object->field('expanded'),
            result: JsonSchema::fromArray(['type' => 'string', 'maxLength' => 30]),
            transforms: [new TransformSetting('bounded')],
        ),
        new FieldMapping(null, 'constant', null, static: 'value', kind: FieldKind::Static),
    ];
    $replica = new Replica(['schema' => $mappings]);
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
    $object = StructureItems::make([
        'fields' => ['code' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 1])],
        'sources' => [],
    ]);
    $replica = TestCase::makeReplica(['schema' => [
        new FieldMapping('code', 'code', $object->field('code')),
    ]]);
    DB::table($replica->destination)->insert([Replica::ID_COLUMN => 'row-1', 'code' => 'A']);

    foreach (['mask' => null, 'bounded' => 30] as $key => $length) {
        $replica->fill(['schema' => [
            new FieldMapping(
                'code',
                'display_code',
                $object->field('code'),
                result: JsonSchema::fromArray([
                    'type' => 'string',
                    ...($length === null ? [] : ['maxLength' => $length]),
                ]),
                transforms: [new TransformSetting($key, $key === 'mask' ? ['replacement' => '***'] : [])],
            ),
        ]]);
        expect($replica->droppedColumns())->toBe([]);
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
        'schema' => [
            new FieldMapping('_idrref', 'external_id', $object->field('_idrref')),
            new FieldMapping('flag', 'flag', $object->field('flag')),
        ],
    ]);

    $updated = [
        $replica->schema[0],
        new FieldMapping('flag', 'flag', JsonSchema::fromArray([
            'type' => 'number',
            'x-ferry-precision' => 15,
            'x-ferry-scale' => 2,
            'multipleOf' => 0.01,
            'exclusiveMinimum' => -10_000_000_000_000,
            'exclusiveMaximum' => 10_000_000_000_000,
        ])),
    ];

    $replica->update(['schema' => $updated]);

    $column = collect(DB::select('PRAGMA table_info("' . $replica->destination . '")'))
        ->firstWhere('name', 'flag');

    expect($column->type)->toBe('numeric');
});

it('pauses replicas when the published structure loses a mapped field', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'sources' => ['title' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ])),
            new FieldMapping('title', 'title', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeItem($upstream, ['revision' => $upstream->structure->draftRevision()]);
    $upstream->structure->publish();

    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Pause)
        ->and(Schema::hasTable($replica->destination))
        ->toBeTrue();
});

it('pauses replicas when a mapped field changes its type', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'sources' => ['title' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ])),
            new FieldMapping('title', 'title', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeItem($upstream, [
        'revision' => $upstream->structure->draftRevision(),
        'sources' => ['title' => [
            'type' => 'number',
            'x-ferry-precision' => 15,
            'x-ferry-scale' => 2,
            'multipleOf' => 0.01,
            'exclusiveMinimum' => -10_000_000_000_000,
            'exclusiveMaximum' => 10_000_000_000_000,
            'title' => 'Title',
        ]],
    ]);
    $upstream->structure->publish();

    expect($replica->fresh()->status)->toBe(ReplicaStatus::Pause);
});

it('reviews source structure without checking configured transform handlers', function (
    string $key,
    array $system,
    ReplicaStatus $status,
) {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeItem($upstream, [
        'fields' => [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
    ]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [new FieldMapping('_marked', null, JsonSchema::fromArray(['type' => 'boolean']), transforms: [
            new TransformSetting($key, [
                'mode' => 'exclude',
                'condition' => '@ == true',
                'missing_or_null_behavior' => 'include',
            ]),
        ])],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeItem($upstream, ['revision' => $upstream->structure->draftRevision(), 'fields' => $system]);
    $upstream->structure->publish();

    expect($replica->fresh()->status)->toBe($status);
})->with([
    'unchanged' => [
        'filter',
        [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
        ReplicaStatus::Active,
    ],
    'filter column removed' => [
        'filter',
        ['_idrref' => JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ])],
        ReplicaStatus::Pause,
    ],
    'filter column type changed' => [
        'filter',
        [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_marked' => JsonSchema::fromArray(['type' => 'string', 'maxLength' => 10]),
        ],
        ReplicaStatus::Pause,
    ],
    'transform no longer registered' => [
        'missing',
        [
            '_idrref' => JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ]),
            '_marked' => JsonSchema::fromArray(['type' => 'boolean']),
        ],
        ReplicaStatus::Active,
    ],
]);

it('keeps replicas active when the structure is republished unchanged', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = ['title' => ['type' => 'string', 'maxLength' => 100, 'title' => 'Title']];
    TestCase::makeItem($upstream, ['sources' => $properties]);

    $replica = TestCase::makeReplica([
        'upstream_id' => $upstream->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', JsonSchema::fromArray([
                'type' => 'string',
                'format' => 'uuid',
                'x-ferry-reference' => true,
                'minLength' => 36,
                'maxLength' => 36,
            ])),
            new FieldMapping('title', 'title', JsonSchema::fromArray(['type' => 'string', 'maxLength' => 100])),
        ],
    ], ['status' => ReplicaStatus::Active]);

    TestCase::makeItem($upstream, [
        'revision' => $upstream->structure->draftRevision(),
        'sources' => $properties,
    ]);
    $upstream->structure->publish();

    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Active)
        ->and(Schema::hasTable($replica->destination))
        ->toBeTrue();
});

it('generates its dynamic table name on creation and preserves it after prefix and code changes', function () {
    config(['phpinnacle-ferry.replica.table_prefix' => 'initial_']);
    $replica = TestCase::makeReplica(['code' => 'orders', 'destination' => 'ignored_table']);
    DB::table('initial_orders')->insert([Replica::ID_COLUMN => 'row-1', 'external_id' => 'source-1']);

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
    $replica = new Replica([
        'destination' => 'decimal_columns',
        'schema' => [new FieldMapping(
            'amount',
            'amount',
            JsonSchema::fromArray([
                'type' => 'number',
                'x-ferry-precision' => 12,
                'x-ferry-scale' => 4,
            ]),
            result: $result !== null ? JsonSchema::fromArray($result) : null,
        )],
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
