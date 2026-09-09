<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\SyncSchemaBuilder;
use PHPinnacle\Ferry\Services\SyncTableManager;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\NumberField;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';

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

$makeProperty = fn (string $id, object $field) => new MetadataProperty(
    id: $id,
    name: $id,
    code: 2,
    kind: PropertyKind::Field,
    label: ucfirst($id),
    title: ucfirst($id),
    field: $field,
);

it('finds source objects only in the connection published root metadata', function () use ($makeObject) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $published = $makeObject($connection);
    $makeObject($connection, ['revision' => 2]);
    $makeObject($connection, ['external_id' => 'draft-only', 'revision' => 2]);
    $makeObject($connection, ['external_id' => 'child', 'parent_id' => $published->id]);

    $otherConnection = TestCase::makeConnection(
        ['code' => 'other-connection'],
        ['status' => StructureStatus::Ready, 'generation' => 1],
    );
    $other = $makeObject($otherConnection);
    $makeObject($otherConnection, ['external_id' => 'other-only']);

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

it('starts in the pending status with a generated destination table', function () {
    $sync = TestCase::makeSync(['code' => 'orders']);

    expect($sync->status)
        ->toBe(SyncStatus::Pending)
        ->and($sync->destination)
        ->toBe('ferry_sync_orders')
        ->and(Schema::hasTable($sync->destination))
        ->toBeTrue();
});

it('persists the mapping schema with the source field definitions', function () {
    $sync = TestCase::makeSync();

    $mappings = $sync->fresh()->schema;

    expect($mappings)
        ->toHaveCount(1)
        ->and($mappings[0]->source)
        ->toBe('_idrref')
        ->and($mappings[0]->column)
        ->toBe('external_id')
        ->and($mappings[0]->field)
        ->toEqual(new ScalarField(FieldType::Id));
});

it('marks the connection in use while a synchronization exists', function () {
    $sync = TestCase::makeSync();

    expect($sync->connection->isInUse())->toBeTrue();
});

it('creates the destination table with technical and mapped columns', function () use ($makeObject, $makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [
            $makeProperty('title', new StringField(length: 100, fixed: false)),
            $makeProperty('price', new NumberField(precision: 15, scale: 2, unsigned: false)),
            $makeProperty('posted', new ScalarField(FieldType::Boolean)),
        ],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => '_idrref', 'column' => 'external_id'],
            ['source' => 'title', 'column' => 'title'],
            ['source' => 'price', 'column' => 'price'],
            ['source' => 'posted', 'column' => 'posted'],
        ]),
    ]);

    expect(Schema::getColumnListing($sync->destination))
        ->toContain(SyncTableManager::ID_COLUMN, 'external_id', 'title', 'price', 'posted')
        ->not->toContain('id', 'created_at', 'updated_at');
});

it('rejects mapping a field that is missing from the object', function () use ($makeObject) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection);

    expect(
        fn () => app(SyncSchemaBuilder::class)->build($object, [['source' => 'missing', 'column' => 'title']]),
    )
        ->toThrow(ValidationException::class);
});

it('adds, renames and drops columns when the mapping changes', function () use ($makeObject, $makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [
            $makeProperty('title', new StringField(length: 100, fixed: false)),
            $makeProperty('price', new NumberField(precision: 15, scale: 2, unsigned: false)),
        ],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => '_idrref', 'column' => 'external_id'],
            ['source' => 'title', 'column' => 'title'],
        ]),
    ]);

    $updated = app(SyncSchemaBuilder::class)->build($object, [
        ['source' => '_idrref', 'column' => 'row_id'],
        ['source' => 'price', 'column' => 'price'],
    ]);

    $sync->update(['schema' => $updated]);

    expect(Schema::getColumnListing($sync->destination))
        ->toContain('row_id', 'price')
        ->not->toContain('external_id', 'title');
});

it('changes the column type when a mapped field changes type', function () use ($makeObject, $makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('flag', new ScalarField(FieldType::Boolean))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'code' => 'type_change',
        'schema' => app(SyncSchemaBuilder::class)->build($object, [
            ['source' => '_idrref', 'column' => 'external_id'],
            ['source' => 'flag', 'column' => 'flag'],
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

it('creates the destination table automatically when a synchronization is created outside Filament', function () {
    $connection = TestCase::makeConnection();

    $sync = Sync::create([
        'connection_id' => $connection->id,
        'name' => 'Direct creation',
        'code' => 'direct-creation',
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
        ],
    ]);

    expect(Schema::hasTable($sync->destination))->toBeTrue();
});

it('drops the destination table when the synchronization is deleted', function () {
    $sync = TestCase::makeSync();

    $sync->delete();

    expect(Schema::hasTable($sync->destination))
        ->toBeFalse()
        ->and(Sync::query()->count())
        ->toBe(0);
});

it('pauses synchronizations when the published structure loses a mapped field', function () use (
    $makeObject,
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $makeObject($connection, [
        'properties' => [$makeProperty('title', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    $makeObject($connection, ['revision' => $connection->draftRevision()]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Pause)
        ->and(Schema::hasTable($sync->destination))
        ->toBeTrue();
});

it('pauses synchronizations when a mapped field changes its type', function () use ($makeObject, $makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $makeObject($connection, [
        'properties' => [$makeProperty('title', new StringField(length: 100, fixed: false))],
    ]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    $makeObject($connection, [
        'revision' => $connection->draftRevision(),
        'properties' => [$makeProperty('title', new NumberField(precision: 15, scale: 2, unsigned: false))],
    ]);
    $connection->publishStructure();

    expect($sync->fresh()->status)->toBe(SyncStatus::Pause);
});

it('keeps synchronizations active when the structure is republished unchanged', function () use (
    $makeObject,
    $makeProperty,
) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $properties = [$makeProperty('title', new StringField(length: 100, fixed: false))];
    $makeObject($connection, ['properties' => $properties]);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);

    $makeObject($connection, [
        'revision' => $connection->draftRevision(),
        'properties' => $properties,
    ]);
    $connection->publishStructure();

    expect($sync->fresh()->status)
        ->toBe(SyncStatus::Active)
        ->and(Schema::hasTable($sync->destination))
        ->toBeTrue();
});

it('builds the mapping schema from source to column bindings', function () use ($makeObject, $makeProperty) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    $object = $makeObject($connection, [
        'properties' => [$makeProperty('title', new StringField(length: 100, fixed: false))],
    ]);

    $schema = app(SyncSchemaBuilder::class)->fromBindings($object, [
        '_idrref' => 'external_id',
        'title' => 'name',
    ]);

    expect($schema)
        ->toHaveCount(2)
        ->and($schema[0]->source)
        ->toBe('_idrref')
        ->and($schema[0]->column)
        ->toBe('external_id')
        ->and($schema[1]->source)
        ->toBe('title')
        ->and($schema[1]->column)
        ->toBe('name');
});
