<?php

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\RosettaStructureAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;
use PHPinnacle\Ferry\Services\UpstreamFactory;
use PHPinnacle\Rosetta\MetadataLoader;
use Tests\TestCase;

uses(TestCase::class);

it('binds the structure adapter without opening a source connection', function () {
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldNotReceive('make');
    app()->instance(UpstreamFactory::class, $factory);

    expect(app(StructureAdapterRegistry::class)->options())
        ->toBe(['rosetta' => '1C Rosetta'])
        ->and(app(StructureAdapterRegistry::class)->getOrFail('rosetta'))
        ->toBeInstanceOf(RosettaStructureAdapter::class);
});

it('prepares serializable context and reads physical primary keys for roots and children', function (
    string $table,
    array $primaryKey,
) {
    $upstream = new Upstream(['driver' => 'pgsql', 'config' => ['database' => 'source']]);
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE Params (filename TEXT, binarydata BLOB)');
    $pdo->exec('CREATE TABLE Config (filename TEXT, binarydata BLOB)');
    $pdo->exec($table);
    $pdo->exec(
        'CREATE TABLE _document1_vt2 (_document1_idrref TEXT, _lineno3 INTEGER, PRIMARY KEY (_document1_idrref, _lineno3))',
    );
    $pdo->prepare('INSERT INTO Params VALUES (?, ?)')->execute([
        'DBNames',
        gzdeflate('{0,{3,{object-id,"Document",1},{section-id,"VT",2},{section-id,"LineNo",3}}}'),
    ]);
    $insert = $pdo->prepare('INSERT INTO Config VALUES (?, ?)');
    $insert->execute(['root', gzdeflate('{2,"configuration-id"}')]);
    $insert->execute(['configuration-id', gzdeflate('{0,0,0,{0,{{c045099e-13b9-4fb6-9d50-fca00202971e,0}}}}')]);
    $insert->execute([
        'object-id',
        gzdeflate(
            '{,{,,,,,,,,,{,{,{,,object-id},Object,{1,en,Object}}}},,{sections,1,{{,{,,,,,{,{,{,,section-id},Lines,{1,en,Lines}}}}},,{properties,0}}},,{properties,0}}',
        ),
    ]);
    $source = Mockery::mock(Connection::class);
    $source->shouldReceive('getPdo')->twice()->andReturn($pdo);
    $source->shouldReceive('getSchemaBuilder')->once()->andReturn(new SQLiteConnection($pdo)->getSchemaBuilder());
    $source->shouldReceive('disconnect')->twice();
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->twice()->with($upstream->credentials())->andReturn($source);
    $adapter = new RosettaStructureAdapter($factory, app(MetadataLoader::class));

    $plan = $adapter->prepare($upstream);
    $objects = $adapter->read($upstream, $plan, 0, 1);

    expect($plan)
        ->toEqual(new StructurePlan(1, [
            'storage_map' => ['object-id' => ['Document' => 1], 'section-id' => ['VT' => 2, 'LineNo' => 3]],
            'type_map' => [],
        ]))
        ->and($objects)
        ->toHaveCount(1)
        ->and($objects[0]->exists)
        ->toBeFalse()
        ->and($objects[0]->external_id)
        ->toBe('object-id')
        ->and($objects[0]->kind)
        ->toBe('document')
        ->and($objects[0]->primary_key)
        ->toBe($primaryKey)
        ->and($objects[0]->schema->annotation('x-primary-key'))
        ->toBe($primaryKey)
        ->and($objects[0]->schema->properties())
        ->toHaveKey('_idrref')
        ->and($objects[0]->relationLoaded('children'))
        ->toBeTrue()
        ->and($objects[0]->children)
        ->toHaveCount(1)
        ->and($objects[0]->children[0]->exists)
        ->toBeFalse()
        ->and($objects[0]->children[0]->primary_key)
        ->toBe(['_document1_idrref', '_lineno3'])
        ->and($objects[0]->children[0]->schema->annotation('x-primary-key'))
        ->toBe(['_document1_idrref', '_lineno3']);
})->with([
    'single column' => ['CREATE TABLE _document1 (_idrref TEXT PRIMARY KEY, _version INTEGER)', ['_idrref']],
    'composite in index order' => [
        'CREATE TABLE _document1 (_idrref TEXT, _version INTEGER, PRIMARY KEY (_version, _idrref))',
        ['_version', '_idrref'],
    ],
    'unique index without a primary key' => ['CREATE TABLE _document1 (_idrref TEXT UNIQUE, _version INTEGER)', []],
]);

it('disconnects and propagates a failed source read', function (string $operation) {
    $source = Mockery::mock(Connection::class);
    $source->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('Source unavailable'));
    $source->shouldReceive('disconnect')->once();
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->once()->andReturn($source);
    $adapter = new RosettaStructureAdapter($factory, app(MetadataLoader::class));
    $upstream = new Upstream(['driver' => 'pgsql', 'config' => []]);

    expect(fn () => (
        $operation === 'prepare'
            ? $adapter->prepare($upstream)
            : $adapter->read($upstream, new StructurePlan(1, ['storage_map' => [], 'type_map' => []]), 0, 1)
    ))
        ->toThrow(RuntimeException::class, 'Source unavailable');
})->with(['prepare', 'read']);
