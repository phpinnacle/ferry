<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Data\TransformSetting;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\SyncTableManager;
use PHPinnacle\Ferry\Services\TransformFactory;
use PHPinnacle\Ferry\Services\TransformRegistry;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Ferry\Transformation;
use PHPinnacle\Ferry\Transformations\MaskField;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('stores ordered record transforms without applying them', function () {
    $sync = TestCase::makeSync(['schema' => []]);
    expect($sync->fresh()->transforms)->toBe([]);

    $settings = [
        new TransformSetting('future_first', ['field' => '_description']),
        new TransformSetting('future_second', ['value' => 'example']),
    ];
    $sync->update(['transforms' => $settings]);
    $saved = $sync->fresh();
    $serialized = array_map(fn (TransformSetting $setting) => $setting->jsonSerialize(), $settings);

    expect($saved->transforms)
        ->toEqual($settings)
        ->and($saved->toArray()['transforms'])
        ->toBe($serialized)
        ->and(json_decode($saved->getRawOriginal('transforms'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($serialized)
        ->and(app(TransformFactory::class)->appliesTo($saved))
        ->toBeTrue()
        ->and(app(TransformFactory::class)->pipeline($saved, new ConnectionMetadata)['transforms'])
        ->toBe('unwrap,rename_keys,rename_values');
});

it('rolls back a synchronization and its table when creation fails', function () {
    $tables = Mockery::mock(SyncTableManager::class);
    $tables
        ->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (Sync $sync) {
            $sync
                ->getConnection()
                ->getSchemaBuilder()
                ->create($sync->destination, function (Blueprint $table) {
                    $table->string(Sync::ID_COLUMN)->primary();
                });

            throw new RuntimeException('Destination creation failed');
        });
    $this->app->instance(SyncTableManager::class, $tables);

    expect(TestCase::makeSync(...))->toThrow(RuntimeException::class, 'Destination creation failed');

    expect(Sync::query()->count())->toBe(0)->and(Schema::hasTable('ferry_sync_test-sync'))->toBeFalse();
});

$makeProperty = fn (string $id, object $field) => new MetadataProperty(
    id: $id,
    name: $id,
    code: 2,
    kind: PropertyKind::Field,
    label: ucfirst($id),
    title: ucfirst($id),
    field: $field,
);

it('finds source objects only in the connection published root metadata', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $published = TestCase::makeMetadata($connection);
    TestCase::makeMetadata($connection, ['revision' => 2]);
    TestCase::makeMetadata($connection, ['external_id' => 'draft-only', 'revision' => 2]);
    TestCase::makeMetadata($connection, ['external_id' => 'child', 'parent_id' => $published->id]);

    $otherConnection = TestCase::makeConnection(
        ['code' => 'other-connection'],
        ['status' => StructureStatus::Ready, 'generation' => 1],
    );
    $other = TestCase::makeMetadata($otherConnection);
    TestCase::makeMetadata($otherConnection, ['external_id' => 'other-only']);

    expect($connection->publishedObject('object-0')?->is($published))
        ->toBeTrue()
        ->and($otherConnection->publishedObject('object-0')?->is($other))
        ->toBeTrue()
        ->and($connection->publishedObject('draft-only'))
        ->toBeNull()
        ->and($connection->publishedObject('child'))
        ->toBeNull()
        ->and($connection->publishedObject('other-only'))
        ->toBeNull()
        ->and($connection->publishedObject('missing'))
        ->toBeNull();
});

it('owns the persisted schema and destination table throughout its lifecycle', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [
            $makeProperty('title', new StringField(length: 100, fixed: false)),
            $makeProperty('price', new NumberField(precision: 15, scale: 2, unsigned: false)),
            $makeProperty('posted', new ScalarField(FieldType::Boolean)),
        ],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => SyncSchema::fromBindings(
            $object,
            [
                '_idrref' => 'external_id',
                'title' => 'title',
                'price' => 'price',
                'posted' => 'posted',
            ],
            app(TransformRegistry::class),
        ),
    ]);

    expect($sync->status)
        ->toBe(SyncStatus::Pending)
        ->and($sync->destination)
        ->toBe('ferry_sync_test-sync')
        ->and($sync->fresh()->schema)
        ->toEqual($sync->schema)
        ->and($connection->isInUse())
        ->toBeTrue()
        ->and(Schema::getColumnListing($sync->destination))
        ->toContain(Sync::ID_COLUMN, 'external_id', 'title', 'price', 'posted')
        ->not->toContain('id', 'created_at', 'updated_at');

    $sync->delete();

    expect(Schema::hasTable($sync->destination))
        ->toBeFalse()
        ->and(Sync::query()->count())
        ->toBe(0)
        ->and($connection->isInUse())
        ->toBeFalse();
});

it('adds, renames and drops columns when the mapping changes', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [
            $makeProperty('title', new StringField(length: 100, fixed: false)),
            $makeProperty('price', new NumberField(precision: 15, scale: 2, unsigned: false)),
        ],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => SyncSchema::fromBindings(
            $object,
            [
                '_idrref' => 'external_id',
                'title' => 'title',
            ],
            app(TransformRegistry::class),
        ),
    ]);

    DB::table($sync->destination)->insert([
        Sync::ID_COLUMN => 'row-1',
        'external_id' => 'source-1',
        'title' => 'Original title',
    ]);

    $updated = SyncSchema::fromBindings(
        $object,
        [
            '_idrref' => 'row_id',
            'price' => 'price',
        ],
        app(TransformRegistry::class),
    );

    $sync->fill(['schema' => $updated]);
    expect($sync->droppedColumns())->toBe(['title']);
    $sync->save();

    expect(DB::table($sync->destination)->sole())->toEqual((object) [
        Sync::ID_COLUMN => 'row-1',
        'row_id' => 'source-1',
        'price' => null,
    ]);
    expect(Schema::getColumnListing($sync->destination))
        ->toContain('row_id', 'price')
        ->not->toContain('external_id', 'title');
});

it('preserves renamed values when a new mapping reuses the original column name', function () {
    $field = new StringField(length: 100, fixed: false);
    $sync = TestCase::makeSync(['schema' => [new FieldMapping('name', 'name', $field)]]);
    DB::table($sync->destination)->insert([Sync::ID_COLUMN => 'row-1', 'name' => 'Original']);

    $sync->fill(['schema' => [
        new FieldMapping('name', 'display_name', $field),
        new FieldMapping('alias', 'name', $field),
    ]]);

    expect($sync->droppedColumns())->toBe([]);
    $sync->save();

    expect(DB::table($sync->destination)->sole())->toEqual((object) [
        Sync::ID_COLUMN => 'row-1',
        'display_name' => 'Original',
        'name' => null,
    ]);
});

it('uses output schema bounds for PostgreSQL varchar columns', function () {
    $registry = app(TransformRegistry::class);
    $registry->register(
        MaskField::make(),
        Transformation::make('bounded')->outputSchema(['type' => 'string', 'maxLength' => 30]),
    );
    $object = new ConnectionMetadata([
        'system' => [
            'bounded' => new StringField(10, false),
            'unbounded' => new StringField(null, false),
            'zero' => new StringField(0, false),
            'masked' => new StringField(1, false),
            'expanded' => new StringField(1, false),
        ],
        'properties' => [],
    ]);
    $mappings = SyncSchema::fromBindings(
        $object,
        array_combine(array_keys($object->system), array_keys($object->system)),
        $registry,
        transforms: [
            'fields' => [
                'masked' => [new TransformSetting('mask', ['replacement' => '***'])],
                'expanded' => [new TransformSetting('bounded')],
            ],
            'constants' => ['constant' => 'value'],
        ],
    );
    $sync = new Sync(['schema' => $mappings]);
    $sync->destination = 'string_outputs';
    config(['phpinnacle-ferry.connection' => 'pgsql']);

    $queries = DB::connection('pgsql')->pretend(fn () => app(SyncTableManager::class)->create($sync));

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
    $registry = app(TransformRegistry::class);
    $registry->register(
        MaskField::make(),
        Transformation::make('bounded')->outputSchema(['type' => 'string', 'maxLength' => 30]),
    );
    $object = new ConnectionMetadata(['system' => ['code' => new StringField(1, false)], 'properties' => []]);
    $sync = TestCase::makeSync(['schema' => SyncSchema::fromBindings($object, ['code' => 'code'], $registry)]);
    DB::table($sync->destination)->insert([Sync::ID_COLUMN => 'row-1', 'code' => 'A']);

    foreach (['mask' => null, 'bounded' => 30] as $key => $length) {
        $sync->fill(['schema' => SyncSchema::fromBindings($object, ['code' => 'display_code'], $registry, transforms: [
            'fields' => ['code' => [new TransformSetting($key, $key === 'mask' ? ['replacement' => '***'] : [])]],
        ])]);
        expect($sync->droppedColumns())->toBe([]);
        config(['phpinnacle-ferry.connection' => 'pgsql']);
        $queries = DB::connection('pgsql')->pretend(fn () => app(SyncTableManager::class)->update($sync));
        config(['phpinnacle-ferry.connection' => null]);
        $sql = implode("\n", array_column($queries, 'query'));
        expect($sql)
            ->toContain('alter column "display_code" type varchar' . ($length === null ? ',' : '(30),'))
            ->not->toContain('drop column', 'using');
        $sync->save();
        expect(DB::table($sync->destination)->value('display_code'))->toBe('A');
    }
});

it('changes the column type when a mapped field changes type', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('flag', new ScalarField(FieldType::Boolean))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'type_change',
        'schema' => SyncSchema::fromBindings(
            $object,
            [
                '_idrref' => 'external_id',
                'flag' => 'flag',
            ],
            app(TransformRegistry::class),
        ),
    ]);

    $updated = [
        $sync->schema[0],
        new FieldMapping('flag', 'flag', new NumberField(precision: 15, scale: 2, unsigned: false)),
    ];

    $sync->update(['schema' => $updated]);

    $column = collect(DB::select('PRAGMA table_info("' . $sync->destination . '")'))
        ->firstWhere('name', 'flag');

    expect($column->type)->toBe('numeric');
});

it('pauses synchronizations when the published structure loses a mapped field', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('title', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    TestCase::makeMetadata($connection, ['revision' => $connection->draftRevision()]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and(Schema::hasTable($sync->destination))
        ->toBeTrue();
});

it('pauses synchronizations when a mapped field changes its type', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('title', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    TestCase::makeMetadata($connection, [
        'revision' => $connection->draftRevision(),
        'properties' => [$makeProperty('title', new NumberField(precision: 15, scale: 2, unsigned: false))],
    ]);
    $connection->publishStructure();

    expect($sync->fresh()->status)->toBe(SyncStatus::Pause);
});

it('pauses synchronizations whose transforms no longer apply to the published structure', function (
    string $key,
    array $system,
    SyncStatus $status,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection, [
        'system' => ['_idrref' => new ScalarField(FieldType::Id), '_marked' => new ScalarField(FieldType::Boolean)],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [new FieldMapping('_marked', null, new ScalarField(FieldType::Boolean), transforms: [
            new TransformSetting($key, [
                'mode' => 'exclude',
                'condition' => '@ == true',
                'missing_or_null_behavior' => 'include',
            ]),
        ])],
    ], ['status' => SyncStatus::Active]);

    TestCase::makeMetadata($connection, ['revision' => $connection->draftRevision(), 'system' => $system]);
    $connection->publishStructure();

    expect($sync->fresh()->status)->toBe($status);
})->with([
    'unchanged' => [
        'filter',
        ['_idrref' => new ScalarField(FieldType::Id), '_marked' => new ScalarField(FieldType::Boolean)],
        SyncStatus::Active,
    ],
    'filter column removed' => ['filter', ['_idrref' => new ScalarField(FieldType::Id)], SyncStatus::Pause],
    'filter column type changed' => [
        'filter',
        ['_idrref' => new ScalarField(FieldType::Id), '_marked' => new StringField(length: 10, fixed: false)],
        SyncStatus::Pause,
    ],
    'transform no longer registered' => [
        'missing',
        ['_idrref' => new ScalarField(FieldType::Id), '_marked' => new ScalarField(FieldType::Boolean)],
        SyncStatus::Pause,
    ],
]);

it('keeps synchronizations active when the structure is republished unchanged', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = [$makeProperty('title', new StringField(length: 100, fixed: false))];
    TestCase::makeMetadata($connection, ['properties' => $properties]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    TestCase::makeMetadata($connection, [
        'revision' => $connection->draftRevision(),
        'properties' => $properties,
    ]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Active)
        ->and(Schema::hasTable($sync->destination))
        ->toBeTrue();
});

it('generates its dynamic table name on creation and preserves it after prefix and code changes', function () {
    config(['phpinnacle-ferry.sync.table_prefix' => 'initial_']);
    $sync = TestCase::makeSync(['code' => 'orders', 'destination' => 'ignored_table']);
    DB::table('initial_orders')->insert([Sync::ID_COLUMN => 'row-1', 'external_id' => 'source-1']);

    expect($sync->fresh()->destination)->toBe('initial_orders');
    expect(Schema::hasTable('ignored_table'))->toBeFalse();

    config(['phpinnacle-ferry.sync.table_prefix' => 'changed_']);
    $sync->update(['code' => 'renamed']);

    expect($sync->fresh()->destination)->toBe('initial_orders');
    expect(DB::table('initial_orders')->value('external_id'))->toBe('source-1');
    expect(Schema::hasTable('changed_renamed'))->toBeFalse();
});
