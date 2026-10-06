<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Rules\SyncSchema;
use PHPinnacle\Ferry\Services\SyncTableManager;
use PHPinnacle\Ferry\Tests\TestCase;
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
        'schema' => SyncSchema::fromBindings($object, [
            '_idrref' => 'external_id',
            'title' => 'title',
            'price' => 'price',
            'posted' => 'posted',
        ]),
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
        'schema' => SyncSchema::fromBindings($object, [
            '_idrref' => 'external_id',
            'title' => 'title',
        ]),
    ]);

    $updated = SyncSchema::fromBindings($object, [
        '_idrref' => 'row_id',
        'price' => 'price',
    ]);

    $sync->update(['schema' => $updated]);

    expect(Schema::getColumnListing($sync->destination))
        ->toContain('row_id', 'price')
        ->not->toContain('external_id', 'title');
});

it('changes the column type when a mapped field changes type', function () use ($makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = TestCase::makeMetadata($connection, [
        'properties' => [$makeProperty('flag', new ScalarField(FieldType::Boolean))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'type_change',
        'schema' => SyncSchema::fromBindings($object, [
            '_idrref' => 'external_id',
            'flag' => 'flag',
        ]),
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
