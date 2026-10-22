<?php

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\FetchStructureChunkJob;
use PHPinnacle\Ferry\Jobs\FinishStructureJob;
use PHPinnacle\Ferry\Jobs\PrepareStructureJob;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Services\ConnectionFactory;
use PHPinnacle\Ferry\Services\StructureImporter;
use PHPinnacle\Ferry\Support\ConnectionErrorFormatter;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Data\MetadataProperty;
use PHPinnacle\Rosetta\Enums\MetadataKind;
use PHPinnacle\Rosetta\Enums\PropertyKind;
use PHPinnacle\Rosetta\Fields\StringField;
use PHPinnacle\Rosetta\MetadataLoader;
use PHPinnacle\Rosetta\StorageMap;
use PHPinnacle\Rosetta\TypeMap;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    config(['phpinnacle-ferry.structure.chunk_size' => 1]);

    Exceptions::fake();
});

$makeImporter = function (int $total) {
    $importer = new class($total) extends StructureImporter {
        public int $prepared = 0;

        /** @var list<int> */
        public array $offsets = [];

        public function __construct(
            private readonly int $total,
        ) {
            parent::__construct(app(ConnectionFactory::class), app(MetadataLoader::class));
        }

        public function importChunk(
            Connection $connection,
            StorageMap $storageMap,
            TypeMap $typeMap,
            int $offset,
            int $limit,
        ): int {
            $this->offsets[] = $offset;

            $last = min($offset + $limit, $this->total);

            for ($position = $offset; $position < $last; $position++) {
                ConnectionMetadata::query()->updateOrCreate([
                    'connection_id' => $connection->id,
                    'revision' => $connection->draftRevision(),
                    'external_id' => sprintf('object-%d', $position),
                ], [
                    'reference_id' => sprintf('reference-%d', $position),
                    'name' => sprintf('_reference%d', $position),
                    'code' => $position + 1,
                    'kind' => MetadataKind::Reference,
                    'label' => sprintf('Object %d', $position),
                    'title' => sprintf('Object %d', $position),
                    'system' => [],
                    'properties' => [],
                    'values' => [],
                    'position' => $position,
                ]);
            }

            return max($last - $offset, 0);
        }

        /** @return array{storageMap: StorageMap, typeMap: TypeMap, total: int} */
        public function prepare(Connection $connection): array
        {
            $this->prepared++;

            return [
                'storageMap' => StorageMap::fromArray(['object-map' => ['Reference' => 1]]),
                'typeMap' => TypeMap::empty(),
                'total' => $this->total,
            ];
        }
    };

    app()->instance(StructureImporter::class, $importer);

    return $importer;
};

it('walks the whole pipeline and publishes the snapshot', function () use ($makeImporter) {
    $importer = $makeImporter(2);

    $connection = TestCase::makeConnection()->fresh();

    expect($connection->status)
        ->toBe(StructureStatus::Ready)
        ->and($connection->generation)
        ->toBe(1)
        ->and($connection->processed)
        ->toBe(2)
        ->and($connection->total)
        ->toBe(2)
        ->and($connection->run_id)
        ->toBeNull()
        ->and($connection->storage_map)
        ->toBeNull()
        ->and($connection->type_map)
        ->toBeNull()
        ->and($connection->last_error)
        ->toBeNull()
        ->and($connection->published_at)
        ->not
        ->toBeNull()
        ->and($importer->prepared)
        ->toBe(1)
        ->and($importer->offsets)
        ->toBe([0, 1])
        ->and($connection->publishedMetadata()->pluck('external_id')->all())
        ->toBe(['object-0', 'object-1']);
});

it('scopes the snapshot to its own connection when eager loaded', function () use ($makeImporter) {
    $makeImporter(1);

    $first = TestCase::makeConnection(['code' => 'first']);
    $second = TestCase::makeConnection(['code' => 'second']);

    $connections = Connection::query()->with('metadata')->get()->keyBy('code');

    expect($connections->get('first')?->metadata->pluck('connection_id')->all())
        ->toBe([$first->id])
        ->and($connections->get('second')?->metadata->pluck('connection_id')->all())
        ->toBe([$second->id])
        ->and($first->fresh()->publishedMetadata()->count())
        ->toBe(1);
});

it('restores the stored maps as typed Rosetta values', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(2);
    $connection = TestCase::makeConnection();

    new PrepareStructureJob($connection->id, (string) $connection->run_id)->handle(app(StructureImporter::class));

    expect($connection->fresh()->storage_map)
        ->toBeInstanceOf(StorageMap::class)
        ->and($connection->fresh()->type_map)
        ->toBeInstanceOf(TypeMap::class);
});

it('preserves property comments when storing and restoring metadata', function (?string $comment) {
    Queue::fake();
    $connection = TestCase::makeConnection();
    $property = new MetadataProperty(
        id: 'property-id',
        name: '_fld1',
        code: 1,
        kind: PropertyKind::Field,
        label: 'Description',
        title: 'Description',
        field: new StringField(100, false),
        comment: $comment,
    );
    $object = TestCase::makeMetadata($connection, ['properties' => [$property]])->fresh();

    expect($object->properties[0])
        ->toEqual($property)
        ->and($object->properties[0]->comment)
        ->toBe($comment)
        ->and($object->toArray()['properties'][0])
        ->toEqual(json_decode(json_encode($property), true));
})->with([null, "Developer note\nKeep this line."]);

it('publishes an empty snapshot when the source has no supported objects', function () use ($makeImporter) {
    $importer = $makeImporter(0);

    $connection = TestCase::makeConnection()->fresh();

    expect($connection->status)
        ->toBe(StructureStatus::Ready)
        ->and($connection->generation)
        ->toBe(1)
        ->and($connection->total)
        ->toBe(0)
        ->and($connection->processed)
        ->toBe(0)
        ->and($connection->published_at)
        ->not
        ->toBeNull()
        ->and($importer->offsets)
        ->toBe([])
        ->and($connection->metadata()->count())
        ->toBe(0);
});

it('resumes instead of reading the source again when the preparation job is redelivered', function () use (
    $makeImporter,
) {
    Queue::fake();

    $importer = $makeImporter(2);
    $connection = TestCase::makeConnection();
    $prepare = new PrepareStructureJob($connection->id, (string) $connection->run_id);

    $prepare->handle($importer);
    new FetchStructureChunkJob($connection->id, (string) $connection->run_id, 0)->handle($importer);
    $prepare->handle($importer);

    expect($importer->prepared)
        ->toBe(1)
        ->and($importer->offsets)
        ->toBe([0])
        ->and($connection->fresh()->processed)
        ->toBe(1);

    Queue::assertPushed(
        FetchStructureChunkJob::class,
        fn (FetchStructureChunkJob $job) => $job->offset === 1,
    );
});

it('imports a repeated chunk without duplicating the snapshot', function () use ($makeImporter) {
    Queue::fake();

    $importer = $makeImporter(2);
    $connection = TestCase::makeConnection();
    $chunk = new FetchStructureChunkJob($connection->id, (string) $connection->run_id, 0);

    new PrepareStructureJob($connection->id, (string) $connection->run_id)->handle($importer);
    $chunk->handle($importer);
    $chunk->handle($importer);

    expect($connection->fresh()->processed)->toBe(1)->and($connection->metadata()->count())->toBe(1);
});

it('starts a new generation as soon as the source credentials change', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(2);
    $connection = TestCase::makeConnection();
    $runId = (string) $connection->run_id;

    $connection->update(['host' => 'replica']);
    $connection->refresh();

    expect($connection->status)
        ->toBe(StructureStatus::Preparing)
        ->and($connection->run_id)
        ->not->toBeNull()->and($connection->run_id)
        ->not->toBe($runId);

    Queue::assertPushed(PrepareStructureJob::class, 2);
});

it('leaves the structure untouched when an unrelated field changes', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(2);
    $connection = TestCase::makeConnection();
    $runId = (string) $connection->run_id;

    $connection->update(['name' => 'Renamed']);

    expect($connection->fresh()->run_id)->toBe($runId);

    Queue::assertPushed(PrepareStructureJob::class, 1);
});

it('ignores every job of a superseded generation', function () use ($makeImporter) {
    Queue::fake();

    $importer = $makeImporter(2);
    $connection = TestCase::makeConnection();
    $runId = (string) $connection->run_id;

    $connection->update(['host' => 'replica']);

    new PrepareStructureJob($connection->id, $runId)->handle($importer);
    new FetchStructureChunkJob($connection->id, $runId, 0)->handle($importer);
    new FinishStructureJob($connection->id, $runId)->handle();

    expect($importer->prepared)
        ->toBe(0)
        ->and($importer->offsets)
        ->toBe([])
        ->and($connection->fresh()->generation)
        ->toBe(0);

    Queue::assertNotPushed(FetchStructureChunkJob::class);
    Queue::assertNotPushed(FinishStructureJob::class);
});

it('imports repeated chunks once and refuses to restore a superseded draft', function () {
    Queue::fake();

    $staleConnection = TestCase::makeConnection()->fresh();
    $connection = Connection::query()->findOrFail($staleConnection->id);
    $storageMap = StorageMap::fromArray(['stale-object' => ['Reference' => 1]]);
    $typeMap = TypeMap::empty();
    $source = app(ConnectionFactory::class)->make(['driver' => 'sqlite', 'database' => ':memory:']);
    $pdo = $source->getPdo();
    $source->statement('CREATE TABLE Config (filename TEXT, binarydata BLOB)');
    $source->insert('INSERT INTO Config VALUES (?, ?)', [
        'stale-object',
        gzdeflate('{,{,,,,,,,,,{,{,{,,stale-object},"Stale object",{1,en,"Stale object"}}}}}'),
    ]);
    $factory = Mockery::mock(ConnectionFactory::class);
    $factory->shouldReceive('make')->andReturnUsing(fn () => new SQLiteConnection($pdo));
    $importer = new StructureImporter($factory, app(MetadataLoader::class));

    $importer->importChunk($staleConnection, $storageMap, $typeMap, 0, 1);
    $first = $connection->metadata()->sole();
    $importer->importChunk($staleConnection, $storageMap, $typeMap, 0, 1);

    expect($connection->metadata()->sole()->id)->toBe($first->id);
    expect($first->name)->toBe('_reference1');
    expect($first->displayTitle())->toBe('Stale object');

    $connection->update(['host' => 'replica']);
    $importer->importChunk($staleConnection, $storageMap, $typeMap, 0, 1);

    expect($connection->metadata()->count())->toBe(0);
});

it('is a safe no-op when the connection was deleted before the jobs run', function () use ($makeImporter) {
    Queue::fake();

    $importer = $makeImporter(2);
    $connection = TestCase::makeConnection();
    $runId = (string) $connection->run_id;

    $connection->delete();

    new PrepareStructureJob($connection->id, $runId)->handle($importer);
    new FetchStructureChunkJob($connection->id, $runId, 0)->handle($importer);
    new FinishStructureJob($connection->id, $runId)->handle();

    expect($importer->prepared)->toBe(0)->and($importer->offsets)->toBe([]);

    Queue::assertNotPushed(FetchStructureChunkJob::class);
    Queue::assertNotPushed(FinishStructureJob::class);
});

it('does not publish twice when the finishing job is redelivered', function () use ($makeImporter) {
    Queue::fake();

    $importer = $makeImporter(1);
    $connection = TestCase::makeConnection();
    $finish = new FinishStructureJob($connection->id, (string) $connection->run_id);

    new PrepareStructureJob($connection->id, (string) $connection->run_id)->handle($importer);
    new FetchStructureChunkJob($connection->id, (string) $connection->run_id, 0)->handle($importer);
    $finish->handle();

    $published = $connection->fresh()->published_at;

    $finish->handle();

    expect($connection->fresh()->generation)
        ->toBe(1)
        ->and($connection->fresh()->published_at?->equalTo($published))
        ->toBeTrue()
        ->and($connection->fresh()->metadata()->count())
        ->toBe(1);
});

it('keeps the published snapshot when a later generation fails', function () use ($makeImporter) {
    Queue::fake();

    $importer = $makeImporter(1);
    $connection = TestCase::makeConnection();

    new PrepareStructureJob($connection->id, (string) $connection->run_id)->handle($importer);
    new FetchStructureChunkJob($connection->id, (string) $connection->run_id, 0)->handle($importer);
    new FinishStructureJob($connection->id, (string) $connection->run_id)->handle();

    $connection->refresh();
    $published = $connection->published_at;
    $connection->prepareStructure(config('phpinnacle-ferry.structure.stale_after'));

    $chunk = new FetchStructureChunkJob($connection->id, (string) $connection->run_id, 0);

    new PrepareStructureJob($connection->id, (string) $connection->run_id)->handle($importer);
    $chunk->handle($importer);
    $chunk->failed(new RuntimeException('boom'));

    $connection->refresh();

    expect($connection->status)
        ->toBe(StructureStatus::Failed)
        ->and($connection->generation)
        ->toBe(1)
        ->and($connection->run_id)
        ->toBeNull()
        ->and($connection->published_at?->equalTo($published))
        ->toBeTrue()
        ->and($connection->metadata()->where('revision', 1)->count())
        ->toBe(1)
        ->and($connection->metadata()->where('revision', 2)->count())
        ->toBe(0);
});

it('reports the original exception and stores only a safe description', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(1);
    $connection = TestCase::makeConnection();
    $job = new PrepareStructureJob($connection->id, (string) $connection->run_id);

    $job->failed(new PDOException('SQLSTATE[08006] host=localhost dbname=db user=user password=secret'));

    $connection->refresh();

    expect($connection->status)
        ->toBe(StructureStatus::Failed)
        ->and($connection->last_error)
        ->toBe(ConnectionErrorFormatter::format(new PDOException))
        ->and($connection->last_error)
        ->not->toContain('secret')->and($connection->last_error)
        ->not->toContain('localhost')->and($connection->last_error)
        ->not->toContain('SQLSTATE');

    Exceptions::assertReported(PDOException::class);
});

it('cannot fail a newer generation from a stale job', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(1);
    $connection = TestCase::makeConnection();
    $stale = new PrepareStructureJob($connection->id, (string) $connection->run_id);

    $connection->update(['host' => 'replica']);

    $stale->failed(new RuntimeException('boom'));

    $connection->refresh();

    expect($connection->status)
        ->toBe(StructureStatus::Preparing)
        ->and($connection->run_id)
        ->not
        ->toBeNull()
        ->and($connection->last_error)
        ->toBeNull();
});

it('retires a stale attempt with a safe reason before starting a new one', function () use ($makeImporter) {
    Queue::fake();

    $makeImporter(1);
    $connection = TestCase::makeConnection([], [
        'heartbeat_at' => now()->subSeconds(config('phpinnacle-ferry.structure.stale_after') + 1),
    ]);

    expect($connection->prepareStructure(config('phpinnacle-ferry.structure.stale_after')))
        ->toBeTrue()
        ->and($connection->fresh()->last_error)
        ->toBe(ConnectionErrorFormatter::stale())
        ->and($connection->fresh()->status)
        ->toBe(StructureStatus::Preparing);
});
