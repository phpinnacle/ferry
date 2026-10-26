<?php

use Illuminate\Database\Connection;
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

it('prepares serializable context and returns unsaved metadata', function () {
    $upstream = new Upstream(['driver' => 'pgsql', 'config' => ['database' => 'source']]);
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE Params (filename TEXT, binarydata BLOB)');
    $pdo->exec('CREATE TABLE Config (filename TEXT, binarydata BLOB)');
    $pdo->prepare('INSERT INTO Params VALUES (?, ?)')->execute([
        'DBNames',
        gzdeflate('{0,{1,{object-id,"Reference",1}}}'),
    ]);
    $insert = $pdo->prepare('INSERT INTO Config VALUES (?, ?)');
    $insert->execute(['root', gzdeflate('{2,"configuration-id"}')]);
    $insert->execute(['configuration-id', gzdeflate('{0,0,0,{0,{{c045099e-13b9-4fb6-9d50-fca00202971e,0}}}}')]);
    $insert->execute(['object-id', gzdeflate('{,{,,,,,,,,,{,{,{,,object-id},"Object",{1,en,"Object"}}}}}')]);
    $source = Mockery::mock(Connection::class);
    $source->shouldReceive('getPdo')->twice()->andReturn($pdo);
    $source->shouldReceive('disconnect')->twice();
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->twice()->with($upstream->credentials())->andReturn($source);
    $adapter = new RosettaStructureAdapter($factory, app(MetadataLoader::class));

    $plan = $adapter->prepare($upstream);
    $objects = $adapter->read($upstream, $plan, 0, 1);

    expect($plan)
        ->toEqual(new StructurePlan(1, ['storage_map' => ['object-id' => ['Reference' => 1]], 'type_map' => []]))
        ->and($objects)
        ->toHaveCount(1)
        ->and($objects[0]->exists)
        ->toBeFalse()
        ->and($objects[0]->external_id)
        ->toBe('object-id')
        ->and($objects[0]->kind)
        ->toBe('reference')
        ->and($objects[0]->relationLoaded('children'))
        ->toBeTrue()
        ->and($objects[0]->children)
        ->toBeEmpty();
});

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
