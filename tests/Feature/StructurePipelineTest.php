<?php

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\StructureAdapter;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\Structure\FetchStructureChunkJob;
use PHPinnacle\Ferry\Jobs\Structure\FinishStructureJob;
use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Models\Structure;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\RosettaStructureAdapter;
use PHPinnacle\Ferry\Services\StructureAdapterRegistry;
use PHPinnacle\Ferry\Services\UpstreamFactory;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\MetadataLoader;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    config([
        'phpinnacle-ferry.structure.chunk_size' => 1,
        'queue.default' => 'sync',
    ]);

    Exceptions::fake();
});

$makeAdapter = function (int $estimated, string $key = 'rosetta') {
    $adapter = new class($estimated, $key) implements StructureAdapter {
        public int $prepared = 0;

        /** @var list<int> */
        public array $offsets = [];

        public function __construct(
            private readonly int $estimated,
            private readonly string $key,
        ) {}

        public function getKey(): string
        {
            return $this->key;
        }

        public function getLabel(): string
        {
            return 'Fixture';
        }

        /** @return list<StructureItem> */
        public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array
        {
            $this->offsets[] = $offset;
            $objects = [];
            $last = min($offset + $limit, $this->estimated);
            for ($position = $offset; $position < $last; $position++) {
                $objects[] = new StructureItem([
                    'external_id' => sprintf('object-%d', $position),
                    'name' => sprintf('table%d', $position),
                    'title' => sprintf('Object %d', $position),
                    'key_column' => 'record_id',
                    'schema' => JsonSchema::fromArray([
                        'type' => 'object',
                        'properties' => [
                            'record_id' => ['type' => 'integer'],
                        ],
                    ]),
                ])->setRelation('children', new \Illuminate\Database\Eloquent\Collection);
            }

            return $objects;
        }

        public function prepare(Upstream $upstream): StructurePlan
        {
            $this->prepared++;

            return new StructurePlan($this->estimated, ['cursor' => 'adapter-owned']);
        }
    };

    app(StructureAdapterRegistry::class)->register($adapter);

    return $adapter;
};

it('walks the whole pipeline and publishes the snapshot', function () use ($makeAdapter) {
    $adapter = $makeAdapter(2);

    $upstream = TestCase::makeUpstream()->fresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Ready)
        ->and($upstream->structure->generation)
        ->toBe(1)
        ->and($upstream->structure->processed)
        ->toBe(2)
        ->and($upstream->structure->estimated)
        ->toBe(2)
        ->and($upstream->structure->run_id)
        ->toBeNull()
        ->and($upstream->structure->context)
        ->toBeNull()
        ->and($adapter->prepared)
        ->toBe(1)
        ->and($adapter->offsets)
        ->toBe([0, 1])
        ->and($upstream->structure->publishedItems()->pluck('external_id')->all())
        ->toBe(['object-0', 'object-1']);
});

it('scopes the snapshot to its own upstream when eager loaded', function () use ($makeAdapter) {
    $makeAdapter(1);

    $first = TestCase::makeUpstream(['code' => 'first']);
    $second = TestCase::makeUpstream(['code' => 'second']);

    $upstreams = Upstream::query()->with('structure.items')->get()->keyBy('code');

    expect($upstreams->get('first')?->structure->items->pluck('upstream_id')->all())
        ->toBe([$first->id])
        ->and($upstreams->get('second')?->structure->items->pluck('upstream_id')->all())
        ->toBe([$second->id])
        ->and($first->fresh()->structure->publishedItems()->count())
        ->toBe(1);
});

it('preserves adapter-owned context between preparation and chunks', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(2);
    $upstream = TestCase::makeUpstream();

    new PrepareStructureJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
    )->handle(app(StructureAdapterRegistry::class));

    expect($upstream->fresh()->structure->readPlan())
        ->toEqual(new StructurePlan(2, ['cursor' => 'adapter-owned']));
});

it('preserves property comments when storing and restoring metadata', function (?string $comment) {
    Queue::fake();
    $upstream = TestCase::makeUpstream();
    $property = ['_fld1' => [
        'type' => 'string',
        'maxLength' => 100,
        'title' => 'Description',
        ...($comment === null ? [] : ['description' => $comment]),
    ]];
    $object = TestCase::makeItem($upstream, ['sources' => $property])->fresh();

    expect($object->schema->properties()['_fld1']->annotation('description'))
        ->toBe($comment)
        ->and($object->toArray()['schema']['properties']['_fld1']['description'] ?? null)
        ->toBe($comment);
})->with([null, "Developer note\nKeep this line."]);

it('publishes an empty snapshot when the source has no supported objects', function () use ($makeAdapter) {
    $adapter = $makeAdapter(0);

    $upstream = TestCase::makeUpstream()->fresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Ready)
        ->and($upstream->structure->generation)
        ->toBe(1)
        ->and($upstream->structure->estimated)
        ->toBe(0)
        ->and($upstream->structure->processed)
        ->toBe(0)
        ->and($adapter->offsets)
        ->toBe([])
        ->and($upstream->structure->items()->count())
        ->toBe(0);
});

it('resumes instead of reading the source again when the preparation job is redelivered', function () use (
    $makeAdapter,
) {
    Queue::fake();

    $adapter = $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $prepare = new PrepareStructureJob($upstream->id, (string) $upstream->structure->run_id);

    $prepare->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
        0,
    )->handle(app(StructureAdapterRegistry::class));
    $prepare->handle(app(StructureAdapterRegistry::class));

    expect($adapter->prepared)
        ->toBe(1)
        ->and($adapter->offsets)
        ->toBe([0])
        ->and($upstream->fresh()->structure->processed)
        ->toBe(1);

    Queue::assertPushed(
        FetchStructureChunkJob::class,
        fn (FetchStructureChunkJob $job) => $job->offset === 1,
    );
});

it('imports a repeated chunk without duplicating the snapshot', function () use ($makeAdapter) {
    Queue::fake();

    $adapter = $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $chunk = new FetchStructureChunkJob($upstream->id, (string) $upstream->structure->run_id, 0);

    new PrepareStructureJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
    )->handle(app(StructureAdapterRegistry::class));
    $chunk->handle(app(StructureAdapterRegistry::class));
    $chunk->handle(app(StructureAdapterRegistry::class));

    expect($upstream->fresh()->structure->processed)->toBe(1)->and($upstream->structure->items()->count())->toBe(1);
});

it('preserves root and child identities when importing a repeated metadata tree', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream();
    $root = TestCase::makeItem($upstream)->replicate();
    $child = $root->replicate()->fill([
        'external_id' => 'child-id',
        'name' => '_reference0_vt1',
        'kind' => 'section',
    ]);
    $root->setRelation('children', new \Illuminate\Database\Eloquent\Collection([$child]));
    $structure = $upstream->structure;

    $structure->importItems([$root], 0);
    $storedRoot = $structure->items()->where('revision', 1)->whereNull('parent_id')->sole();
    $storedChild = $storedRoot->children()->sole();
    $structure->importItems([$root], 0);

    expect($structure->items()->where('revision', 1)->count())
        ->toBe(2)
        ->and($storedRoot->fresh()->children()->sole()->id)
        ->toBe($storedChild->id)
        ->and($storedChild->structure->is($structure))
        ->toBeTrue();
});

it('starts a new generation as soon as the source credentials change', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $runId = (string) $upstream->structure->run_id;

    $upstream->update(['config' => [...$upstream->config, 'host' => 'replica']]);
    $upstream->refresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Preparing)
        ->and($upstream->structure->run_id)
        ->not->toBeNull()->and($upstream->structure->run_id)
        ->not->toBe($runId);

    Queue::assertPushed(PrepareStructureJob::class, 2);
});

it('leaves the structure untouched when an unrelated field changes', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $runId = (string) $upstream->structure->run_id;

    $upstream->update(['name' => 'Renamed']);

    expect($upstream->fresh()->structure->run_id)->toBe($runId);

    Queue::assertPushed(PrepareStructureJob::class, 1);
});

it('keeps the latest published revision when credentials change on an already loaded upstream', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream, ['revision' => 1]);
    $upstream->fresh()->structure->publish();

    $upstream->update(['config' => [...$upstream->config, 'host' => 'replica']]);

    expect($upstream->fresh()->structure->generation)
        ->toBe(1)
        ->and($upstream->fresh()->structure->publishedItems()->count())
        ->toBe(1);
});

it('does not treat upstream edits as progress of a stale structure run', function () {
    Queue::fake();
    $staleAfter = config('phpinnacle-ferry.structure.stale_after');
    $upstream = TestCase::makeUpstream([], ['updated_at' => now()->subSeconds($staleAfter + 1)]);

    $upstream->update(['name' => 'Renamed']);

    expect($upstream->fresh()->structure->canPrepare($staleAfter))->toBeTrue();
});

it('ignores every job of a superseded generation', function () use ($makeAdapter) {
    Queue::fake();

    $adapter = $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $runId = (string) $upstream->structure->run_id;

    $upstream->update(['config' => [...$upstream->config, 'host' => 'replica']]);

    new PrepareStructureJob($upstream->id, $runId)->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob($upstream->id, $runId, 0)->handle(app(StructureAdapterRegistry::class));
    new FinishStructureJob($upstream->id, $runId)->handle();

    expect($adapter->prepared)
        ->toBe(0)
        ->and($adapter->offsets)
        ->toBe([])
        ->and($upstream->fresh()->structure->generation)
        ->toBe(0);

    Queue::assertNotPushed(FetchStructureChunkJob::class);
    Queue::assertNotPushed(FinishStructureJob::class);
});

it('imports repeated chunks once and refuses to restore a superseded draft', function () {
    Queue::fake();

    $staleUpstream = TestCase::makeUpstream()->fresh();
    $upstream = Upstream::query()->findOrFail($staleUpstream->id);
    $source = app(UpstreamFactory::class)->make(['driver' => 'sqlite', 'database' => ':memory:']);
    $pdo = $source->getPdo();
    $source->statement('CREATE TABLE Config (filename TEXT, binarydata BLOB)');
    $source->insert('INSERT INTO Config VALUES (?, ?)', [
        'stale-object',
        gzdeflate('{,{,,,,,,,,,{,{,{,,stale-object},"Stale object",{1,en,"Stale object"}}}}}'),
    ]);
    $factory = Mockery::mock(UpstreamFactory::class);
    $factory->shouldReceive('make')->andReturnUsing(fn () => new SQLiteConnection($pdo));
    $adapter = new RosettaStructureAdapter($factory, app(MetadataLoader::class));
    $plan = new StructurePlan(1, ['storage_map' => ['stale-object' => ['Reference' => 1]], 'type_map' => []]);

    $staleUpstream->structure->importItems($adapter->read($staleUpstream, $plan, 0, 1), 0);
    $first = $upstream->structure->items()->sole();
    $staleUpstream->structure->importItems($adapter->read($staleUpstream, $plan, 0, 1), 0);

    expect($upstream->structure->items()->sole()->id)->toBe($first->id);
    expect($first->name)->toBe('_reference1');
    expect($first->displayTitle())->toBe('Stale object');

    $upstream->update(['config' => [...$upstream->config, 'host' => 'replica']]);
    $staleUpstream->structure->importItems($adapter->read($staleUpstream, $plan, 0, 1), 0);

    expect($upstream->structure->items()->count())->toBe(0);
});

it('is a safe no-op when the upstream was deleted before the jobs run', function () use ($makeAdapter) {
    Queue::fake();

    $adapter = $makeAdapter(2);
    $upstream = TestCase::makeUpstream();
    $runId = (string) $upstream->structure->run_id;

    $upstream->delete();

    new PrepareStructureJob($upstream->id, $runId)->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob($upstream->id, $runId, 0)->handle(app(StructureAdapterRegistry::class));
    new FinishStructureJob($upstream->id, $runId)->handle();

    expect($adapter->prepared)->toBe(0)->and($adapter->offsets)->toBe([]);

    Queue::assertNotPushed(FetchStructureChunkJob::class);
    Queue::assertNotPushed(FinishStructureJob::class);
});

it('does not publish twice when the finishing job is redelivered', function () use ($makeAdapter) {
    Queue::fake();

    $adapter = $makeAdapter(1);
    $upstream = TestCase::makeUpstream();
    $finish = new FinishStructureJob($upstream->id, (string) $upstream->structure->run_id);

    new PrepareStructureJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
    )->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
        0,
    )->handle(app(StructureAdapterRegistry::class));
    $finish->handle();

    $finish->handle();

    expect($upstream->fresh()->structure->generation)
        ->toBe(1)
        ->and($upstream->fresh()->structure->items()->count())
        ->toBe(1);
});

it('keeps the published snapshot when a later generation fails', function () use ($makeAdapter) {
    Queue::fake();

    $adapter = $makeAdapter(1);
    $upstream = TestCase::makeUpstream();

    new PrepareStructureJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
    )->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
        0,
    )->handle(app(StructureAdapterRegistry::class));
    new FinishStructureJob($upstream->id, (string) $upstream->structure->run_id)->handle();

    $upstream->refresh();
    $upstream->structure->prepare(config('phpinnacle-ferry.structure.stale_after'));

    $chunk = new FetchStructureChunkJob($upstream->id, (string) $upstream->structure->run_id, 0);

    new PrepareStructureJob(
        $upstream->id,
        (string) $upstream->structure->run_id,
    )->handle(app(StructureAdapterRegistry::class));
    $chunk->handle(app(StructureAdapterRegistry::class));
    $chunk->failed(new RuntimeException('boom'));

    $upstream->refresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Failed)
        ->and($upstream->structure->generation)
        ->toBe(1)
        ->and($upstream->structure->run_id)
        ->toBeNull()
        ->and($upstream->structure->items()->where('revision', 1)->count())
        ->toBe(1)
        ->and($upstream->structure->items()->where('revision', 2)->count())
        ->toBe(0);
});

it('reports the original exception when preparation fails', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(1);
    $upstream = TestCase::makeUpstream();
    $job = new PrepareStructureJob($upstream->id, (string) $upstream->structure->run_id);

    $exception = new PDOException('SQLSTATE[08006] host=localhost dbname=db user=user password=secret');
    $job->failed($exception);

    $upstream->refresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Failed)
        ->and($upstream->structure->run_id)
        ->toBeNull();

    Exceptions::assertReported(fn (PDOException $reported) => $reported === $exception);
});

it('cannot fail a newer generation from a stale job', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(1);
    $upstream = TestCase::makeUpstream();
    $stale = new PrepareStructureJob($upstream->id, (string) $upstream->structure->run_id);

    $upstream->update(['config' => [...$upstream->config, 'host' => 'replica']]);

    $stale->failed(new RuntimeException('boom'));

    $upstream->refresh();

    expect($upstream->structure->status)
        ->toBe(StructureStatus::Preparing)
        ->and($upstream->structure->run_id)
        ->not->toBeNull();
});

it('retires a stale attempt before starting a new one', function () use ($makeAdapter) {
    Queue::fake();

    $makeAdapter(1);
    $upstream = TestCase::makeUpstream([], [
        'updated_at' => now()->subSeconds(config('phpinnacle-ferry.structure.stale_after') + 1),
    ]);

    $previousRun = $upstream->structure->run_id;

    expect($upstream->structure->prepare(config('phpinnacle-ferry.structure.stale_after')))
        ->toBeTrue()
        ->and($upstream->fresh()->structure->run_id)
        ->not
        ->toBe($previousRun)
        ->and($upstream->fresh()->structure->status)
        ->toBe(StructureStatus::Preparing);
});

it('uses progress updates as activity for stale run detection', function () {
    Queue::fake();
    $staleAfter = config('phpinnacle-ferry.structure.stale_after');
    $upstream = TestCase::makeUpstream([], ['updated_at' => now()->subSeconds($staleAfter + 1)]);

    expect($upstream->structure->canPrepare($staleAfter))->toBeTrue();
    $upstream->structure->progressTo(1);

    expect($upstream->structure->canPrepare($staleAfter))->toBeFalse();
});

it('resumes queued preparation from persisted state', function () {
    Queue::fake();
    $upstream = TestCase::makeUpstream([], ['generation' => 2]);
    $structure = $upstream->structure;
    $context = ['cursor' => 'next-page'];
    $structure->plan(new StructurePlan(3, $context));
    $structure->progressTo(1);
    $structure->forceFill(['updated_at' => now()->subMinutes(5)])->save();
    $runId = $structure->run_id;
    $updatedAt = $structure->updated_at;
    $job = unserialize(serialize(new PrepareStructureJob($upstream->id, $runId)));
    $structure = $upstream->fresh()->structure;

    expect($structure->generation)
        ->toBe(2)
        ->and($structure->processed)
        ->toBe(1)
        ->and($structure->context)
        ->toBe($context)
        ->and($structure->updated_at->equalTo($updatedAt))
        ->toBeTrue();

    $adapter = Mockery::mock(StructureAdapter::class);
    $adapter->shouldReceive('getKey')->andReturn('rosetta');
    $adapter->shouldNotReceive('prepare');
    app(StructureAdapterRegistry::class)->register($adapter);
    $job->handle(app(StructureAdapterRegistry::class));
    Queue::assertPushed(
        FetchStructureChunkJob::class,
        fn (FetchStructureChunkJob $chunk) => $chunk->runId === $runId && $chunk->offset === 1,
    );
    Queue::assertPushed(PrepareStructureJob::class, 1);
});

it('leaves structure pending and never reads or queues work without an adapter', function () {
    Queue::fake();
    $registry = Mockery::mock(StructureAdapterRegistry::class);
    $registry->shouldNotReceive('getOrFail');
    $upstream = TestCase::makeUpstream(['adapter' => null]);
    $structure = $upstream->structure;

    expect(new Upstream()->adapter)
        ->toBeNull()
        ->and($structure->status)
        ->toBe(StructureStatus::Pending)
        ->and($structure->run_id)
        ->toBeNull()
        ->and($structure->readPlan())
        ->toBeNull()
        ->and($structure->prepare(60))
        ->toBeFalse();

    new PrepareStructureJob($upstream->id, 'old-run')->handle($registry);
    new FetchStructureChunkJob($upstream->id, 'old-run', 0)->handle($registry);
    new FinishStructureJob($upstream->id, 'old-run')->handle();
    $upstream->update(['config' => [...$upstream->config, 'host' => 'changed']]);

    expect($upstream->fresh()->structure->status)
        ->toBe(StructureStatus::Pending)
        ->and($upstream->structure->generation)
        ->toBe(0)
        ->and($upstream->structure->run_id)
        ->toBeNull();
    Queue::assertNotPushed(PrepareStructureJob::class);
    Queue::assertNotPushed(FetchStructureChunkJob::class);
});

it('uses the selected adapter key for preparation and every chunk', function () use ($makeAdapter) {
    $selected = $makeAdapter(2, 'selected');
    $other = $makeAdapter(5, 'other');
    $upstream = TestCase::makeUpstream(['adapter' => 'selected'])->fresh();

    expect($upstream->adapter)
        ->toBe('selected')
        ->and($selected->prepared)
        ->toBe(1)
        ->and($selected->offsets)
        ->toBe([0, 1])
        ->and($other->prepared)
        ->toBe(0)
        ->and($other->offsets)
        ->toBe([])
        ->and($upstream->structure->status)
        ->toBe(StructureStatus::Ready)
        ->and($upstream->structure->publishedItems()->count())
        ->toBe(2);
});

it('restarts reading when an adapter is selected for a pending upstream', function () use ($makeAdapter) {
    $adapter = $makeAdapter(1, 'selected');
    $upstream = TestCase::makeUpstream(['adapter' => null]);
    expect($upstream->structure->status)->toBe(StructureStatus::Pending);

    $upstream->update(['adapter' => 'selected']);
    $upstream->refresh();

    expect($adapter->prepared)
        ->toBe(1)
        ->and($upstream->structure->status)
        ->toBe(StructureStatus::Ready)
        ->and($upstream->structure->generation)
        ->toBe(1);
});

it('invalidates the old run and context when the adapter changes', function (?string $key) use ($makeAdapter) {
    Queue::fake();
    $makeAdapter(1, 'selected');
    $upstream = TestCase::makeUpstream([], ['generation' => 1]);
    $structure = $upstream->structure;
    $structure->plan(new StructurePlan(2, ['old' => 'context']));
    $runId = $structure->run_id;
    $published = TestCase::makeItem($upstream);
    TestCase::makeItem($upstream, ['revision' => 2]);

    $upstream->update(['adapter' => $key]);
    new PrepareStructureJob($upstream->id, $runId)->handle(app(StructureAdapterRegistry::class));
    new FetchStructureChunkJob($upstream->id, $runId, 0)->handle(app(StructureAdapterRegistry::class));
    new FinishStructureJob($upstream->id, $runId)->handle();
    $structure = $upstream->fresh()->structure;

    expect($structure->status)
        ->toBe($key === null ? StructureStatus::Pending : StructureStatus::Preparing)
        ->and($structure->run_id)
        ->not
        ->toBe($runId)
        ->and($structure->readPlan())
        ->toBeNull()
        ->and($structure->generation)
        ->toBe(1)
        ->and($structure->items()->where('revision', 2)->count())
        ->toBe(0)
        ->and($structure->publishedItems()->sole()->id)
        ->toBe($published->id);
    if ($key === null) {
        expect($structure->run_id)->toBeNull();
    }
    Queue::assertPushed(PrepareStructureJob::class, $key === null ? 1 : 2);
    Queue::assertNotPushed(FetchStructureChunkJob::class);
})->with([null, 'selected']);

it('does not save a plan or schedule chunks after the adapter is cleared during preparation', function () {
    Queue::fake();
    $adapter = Mockery::mock(StructureAdapter::class);
    $adapter->shouldReceive('getKey')->andReturn('selected');
    $adapter
        ->shouldReceive('prepare')
        ->once()
        ->andReturnUsing(function (Upstream $upstream) {
            $upstream->update(['adapter' => null]);

            return new StructurePlan(1, ['stale' => 'plan']);
        });
    $adapter->shouldNotReceive('read');
    app(StructureAdapterRegistry::class)->register($adapter);
    $upstream = TestCase::makeUpstream(['adapter' => 'selected']);

    new PrepareStructureJob($upstream->id, $upstream->structure->run_id)->handle(app(StructureAdapterRegistry::class));

    expect($upstream->fresh()->structure->status)
        ->toBe(StructureStatus::Pending)
        ->and($upstream->structure->readPlan())
        ->toBeNull();
    Queue::assertNotPushed(FetchStructureChunkJob::class);
});

it('skips a phase if its adapter is cleared after the run lookup', function (string $phase) {
    Queue::fake();
    $upstream = TestCase::makeUpstream();
    $structure = $upstream->structure;
    $structure->plan(new StructurePlan(1, ['cursor' => 'old']));
    $runId = $structure->run_id;
    $cleared = false;
    Structure::retrieved(function (Structure $retrieved) use ($upstream, $runId, &$cleared) {
        if (!$cleared && $retrieved->upstream_id === $upstream->id && $retrieved->run_id === $runId) {
            $cleared = true;
            $upstream->update(['adapter' => null]);
        }
    });
    $registry = Mockery::mock(StructureAdapterRegistry::class);
    $registry->shouldNotReceive('getOrFail');
    $job = $phase === 'prepare'
        ? new PrepareStructureJob($upstream->id, $runId)
        : new FetchStructureChunkJob($upstream->id, $runId, 0);

    $job->handle($registry);

    expect($cleared)->toBeTrue()->and($upstream->fresh()->structure->status)->toBe(StructureStatus::Pending);
    Queue::assertNotPushed(FetchStructureChunkJob::class);
})->with(['prepare', 'chunk']);
