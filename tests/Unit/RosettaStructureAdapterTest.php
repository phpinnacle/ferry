<?php

use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Origins\Kafka\SourceFactory;
use PHPinnacle\Ferry\Services\RosettaAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;
use PHPinnacle\Ferry\Services\UpstreamFactory;
use Tests\TestCase;

uses(TestCase::class);

it('binds the structure adapter without opening a source connection', function () {
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldNotReceive('make');
    app()->instance(UpstreamFactory::class, $factory);

    expect(app(StructureAdapterRegistry::class)->options())
        ->toBe(['rosetta' => '1C Rosetta', 'eloquent' => 'Eloquent'])
        ->and(app(StructureAdapterRegistry::class)->getOrFail('rosetta'))
        ->toBeInstanceOf(RosettaAdapter::class);
});

it('prepares serializable context and reads physical primary keys for roots and children', function (
    string $table,
    array $primaryKey,
    string $driver,
    int $slot,
) {
    $dsn = getenv('FERRY_TEST_PGSQL_DSN');

    if ($driver === 'pgsql' && $dsn === false) {
        $this->markTestSkipped('Set FERRY_TEST_PGSQL_DSN to test PostgreSQL catalog reads.');
    }

    $upstream = new Upstream(['code' => 'source', 'config' => ['driver' => 'pgsql', 'database' => 'source']]);
    $pdo = new PDO($driver === 'pgsql' ? $dsn : 'sqlite::memory:');

    if ($driver === 'pgsql') {
        $pdo->beginTransaction();
        $pdo->exec('CREATE SCHEMA ferry_rosetta_test');
        $pdo->exec('SET LOCAL search_path TO ferry_rosetta_test');
    }

    $binaryType = $driver === 'pgsql' ? 'BYTEA' : 'BLOB';
    $suffix = $slot === 0 ? '' : 'x' . $slot;
    $pdo->exec("CREATE TABLE Params (filename TEXT, binarydata {$binaryType})");
    $pdo->exec("CREATE TABLE Config (filename TEXT, binarydata {$binaryType})");
    $pdo->exec("CREATE TABLE SchemaStorage (SchemaID INTEGER, Status INTEGER, CurrentSchema {$binaryType})");
    $schema = $pdo->prepare('INSERT INTO SchemaStorage VALUES (?, 100, ?)');
    $schema->bindValue(1, $slot);
    $schema->bindValue(
        2,
        "\xEF\xBB\xBF"
        . '{0,{1,{"Document1","N",1,"",{0},{1,{"VT2","I",0,"Document1",{0},{0},{0},1,"S",{0},{0},"",0,0}},{0},1,"R",{0},{0},"",0,0}}}',
        PDO::PARAM_LOB,
    );
    $schema->execute();

    if ($slot !== 0) {
        $pdo->exec('CREATE TABLE _document1 (_idrref TEXT, _version INTEGER)');
        $pdo->exec('CREATE TABLE _document1_vt2 (_document1_idrref TEXT, _lineno3 INTEGER)');
    }

    $pdo->exec(str_replace('_document1 ', '_document1' . $suffix . ' ', $table));
    $sectionPrimaryKey = $primaryKey === [] ? [] : ['_document1_idrref', '_lineno3'];
    $sectionConstraint = $sectionPrimaryKey === [] ? 'UNIQUE' : 'PRIMARY KEY';
    $pdo->exec(
        "CREATE TABLE _document1_vt2{$suffix} (_document1_idrref TEXT, _lineno3 INTEGER, {$sectionConstraint} (_document1_idrref, _lineno3))",
    );
    $pdo->exec("INSERT INTO _document1{$suffix} VALUES ('record-id', 1)");
    $pdo->exec("INSERT INTO _document1_vt2{$suffix} VALUES ('record-id', 1)");

    foreach ([
        ['Params', 'DBNames', '{0,{3,{object-id,"Document",1},{section-id,"VT",2},{section-id,"LineNo",3}}}'],
        ['Config', 'root', '{2,"configuration-id"}'],
        ['Config', 'configuration-id', '{0,0,0,{0,{{c045099e-13b9-4fb6-9d50-fca00202971e,0}}}}'],
        [
            'Config',
            'object-id',
            '{,{,,,,,,,,,{,{,{,,object-id},Object,{1,en,Object}}}},,{sections,1,{{,{,,,,,{,{,{,,section-id},Lines,{1,en,Lines}}}}},,{properties,0}}},,{properties,0}}',
        ],
    ] as [$metadataTable, $filename, $contents]) {
        $insert = $pdo->prepare("INSERT INTO {$metadataTable} VALUES (?, ?)");
        $insert->bindValue(1, $filename);
        $insert->bindValue(2, gzdeflate($contents), PDO::PARAM_LOB);
        $insert->execute();
    }

    $connection = $driver === 'pgsql' ? PostgresConnection::class : SQLiteConnection::class;
    $sources = [new $connection($pdo), new $connection($pdo)];
    $sources[1]->enableQueryLog();
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->with($upstream->config)->andReturn(...$sources);
    $adapter = app()->make(RosettaAdapter::class, ['factory' => $factory]);

    $plan = $adapter->prepare($upstream);
    $objects = $adapter->read($upstream, $plan, 0, 1);
    $owner = $pdo->query('SELECT _document1_idrref FROM ' . $objects[0]->children[0]->name)->fetchColumn();

    if ($driver === 'pgsql') {
        $pdo->rollBack();
    }

    expect($sources[1]->getQueryLog())->toHaveCount($driver === 'pgsql' ? 1 : 2);
    expect($sources[0]->getRawPdo())->toBeNull();
    expect($sources[1]->getRawPdo())->toBeNull();
    expect($objects[0]->name)->toBe('_document1' . $suffix);
    expect($objects[0]->children[0]->name)->toBe('_document1_vt2' . $suffix);
    expect($owner)->toBe('record-id');
    $builder = app(SourceFactory::class);
    expect($builder->topic($upstream, $objects[0]))->toBe('ferry.source.public._document1' . $suffix);
    expect($builder->table($upstream, $objects[0]->children[0]))->toBe('public._document1_vt2' . $suffix);
    expect($builder->topic($upstream, $objects[0]->children[0]))->toBe('ferry.source.public._document1_vt2' . $suffix);
    expect($plan)
        ->toEqual(new StructurePlan(1, [
            'storage_map' => ['object-id' => ['Document' => 1], 'section-id' => ['VT' => 2, 'LineNo' => 3]],
            'type_map' => [],
            'table_map' => $slot === 0
                ? []
                : [
                    '_document1' => '_document1' . $suffix,
                    '_document1_vt2' => '_document1_vt2' . $suffix,
                ],
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
        ->and($objects[0]->schema->annotation('x-ferry-primary'))
        ->toBe($primaryKey)
        ->and($objects[0]->schema->required())
        ->toBe($primaryKey)
        ->and($objects[0]->schema->properties())
        ->toHaveKey('_idrref')
        ->and($objects[0]->field('_idrref')->annotation('x-ferry-converter'))
        ->toBe('uuid')
        ->and($objects[0]->relationLoaded('children'))
        ->toBeTrue()
        ->and($objects[0]->children)
        ->toHaveCount(1)
        ->and($objects[0]->children[0]->exists)
        ->toBeFalse()
        ->and($objects[0]->children[0]->primary_key)
        ->toBe($sectionPrimaryKey)
        ->and($objects[0]->children[0]->schema->annotation('x-ferry-primary'))
        ->toBe($sectionPrimaryKey)
        ->and($objects[0]->children[0]->schema->required())
        ->toBe($sectionPrimaryKey)
        ->and($objects[0]->children[0]->field('_document1_idrref')->annotation('x-ferry-converter'))
        ->toBe('uuid')
        ->and($objects[0]->children[0]->field('_keyfield')->annotation('x-ferry-converter'))
        ->toBe('integer');
})->with([
    'single column' => ['CREATE TABLE _document1 (_idrref TEXT PRIMARY KEY, _version INTEGER)', ['_idrref']],
    'composite in index order' => [
        'CREATE TABLE _document1 (_idrref TEXT, _version INTEGER, PRIMARY KEY (_version, _idrref))',
        ['_version', '_idrref'],
    ],
    'unique index without a primary key' => ['CREATE TABLE _document1 (_idrref TEXT UNIQUE, _version INTEGER)', []],
])->with(['sqlite', 'pgsql'])->with(['main storage' => [0], 'extension storage' => [1], 'another storage slot' => [3]]);

it('disconnects and propagates a failed source read', function (string $operation) {
    $source = Mockery::mock(Connection::class);
    $source->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('Source unavailable'));
    $source->shouldReceive('disconnect')->once();
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->once()->andReturn($source);
    $adapter = app()->make(RosettaAdapter::class, ['factory' => $factory]);
    $upstream = new Upstream(['config' => ['driver' => 'pgsql']]);

    expect(fn () => (
        $operation === 'prepare'
            ? $adapter->prepare($upstream)
            : $adapter->read($upstream, new StructurePlan(1, ['storage_map' => [], 'type_map' => []]), 0, 1)
    ))
        ->toThrow(RuntimeException::class, 'Source unavailable');
})->with(['prepare', 'read']);
