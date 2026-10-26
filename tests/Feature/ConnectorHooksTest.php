<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Eidos\Forms\JsonSchema;
use PHPinnacle\Ferry\Contracts\Origin;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Exceptions\ConnectorOperationFailed;
use PHPinnacle\Ferry\Jobs\Connector\SynchronizeConnectorsJob;
use PHPinnacle\Ferry\Jobs\Structure\FetchStructureChunkJob;
use PHPinnacle\Ferry\Jobs\Structure\FinishStructureJob;
use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fakes\Origin as FakeOrigin;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';
uses(TestCase::class);

function ferry_use_origin(Origin $origin): void
{
    $origins = Mockery::mock(OriginFactory::class);
    $origins->shouldReceive('for')->andReturn($origin);
    $origins->shouldReceive('create')->andReturn($origin);
    app()->instance(OriginFactory::class, $origins);
}

function ferry_drain_connectors(): void
{
    $queue = Queue::getFacadeRoot()->queue->connection();
    $job = $queue->pop(config('phpinnacle-ferry.queue'));
    while ($job !== null) {
        $job->fire();
        $job->delete();
        $job = $queue->pop(config('phpinnacle-ferry.queue'));
    }
}

beforeEach(function () {
    Queue::fake()->except(SynchronizeConnectorsJob::class);
});

it('does nothing for an upstream without replicas', function () {
    TestCase::makeUpstream();
    expect(Connector::query()->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('routes every Ferry job to the configured queue or the Laravel default', function (?string $queue) {
    config([
        'phpinnacle-ferry.queue' => $queue,
        'queue.connections.database.queue' => 'application',
    ]);
    Queue::fake()->except([
        PrepareStructureJob::class,
        FetchStructureChunkJob::class,
        FinishStructureJob::class,
        SynchronizeConnectorsJob::class,
    ]);

    PrepareStructureJob::dispatch('upstream-id', 'run-id');
    FetchStructureChunkJob::dispatch('upstream-id', 'run-id', 0);
    FinishStructureJob::dispatch('upstream-id', 'run-id');
    SynchronizeConnectorsJob::dispatch('upstream-id');

    expect(DB::table('jobs')->pluck('queue')->all())->toBe(array_fill(0, 4, $queue ?? 'application'));
})->with(['named queue' => ['ferry-imports'], 'Laravel default' => [null]]);

it('queues connector jobs only after the owner transaction commits', function (bool $commit) {
    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream);
    $database = $upstream->getConnection();
    $database->beginTransaction();
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id]);
    expect(DB::table('jobs')->count())
        ->toBe(0)
        ->and($replica->fresh()->status)
        ->toBe(ReplicaStatus::Pending)
        ->and($replica->fresh()->connector?->applied_config)
        ->toBeNull()
        ->and($replica->fresh()->connector_id)
        ->not->toBeNull()->and($upstream->fresh()->connector_id)
        ->not->toBeNull();
    $commit ? $database->commit() : $database->rollBack();
    expect(Replica::query()->count())
        ->toBe($commit ? 1 : 0)
        ->and(Connector::query()->count())
        ->toBe($commit ? 2 : 0)
        ->and(DB::table('jobs')->count())
        ->toBe($commit ? 2 : 0)
        ->and(Schema::hasTable($replica->destination))
        ->toBe($commit)
        ->and(DB::table('jobs')->pluck('payload')->implode(''))
        ->not->toContain('secret');
})->with(['commit' => true, 'rollback' => false]);

it('applies source before sinks and activates the replica after success', function () {
    $origin = app(OriginFactory::class)->create('kafka');
    $replica = TestCase::makeReplica();
    expect($origin->operations)->toBe([]);
    ferry_drain_connectors();
    expect(array_slice($origin->operations, offset: 0, length: 3))
        ->toBe([
            ['create', 'ferry-source-test-upstream'],
            ['create', 'ferry-sink-test-replica'],
            ['start',  'ferry-sink-test-replica'],
        ])
        ->and($replica->fresh()->status)
        ->toBe(ReplicaStatus::Active)
        ->and($replica->fresh()->connector?->applied_config)
        ->toBe($replica->fresh()->connector?->config)
        ->and(DB::table('jobs')->count())
        ->toBe(0);
});

it('does not enqueue updates for unchanged config or status checks', function () {
    $replica = TestCase::makeReplica();
    ferry_drain_connectors();
    $replica->refresh();
    $replica->update(['name' => 'Renamed']);
    $replica->connector->recordStatus(ConnectorStatus::Running);
    $replica->connector->save();
    app(ConnectorManager::class)->checkStatus($replica);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('uses current configuration when old jobs are still pending', function () {
    $replica = TestCase::makeReplica();
    $replica->update(['schema' => [new FieldMapping('_idrref', 'latest_id', JsonSchema::fromArray([
        'type' => 'string',
        'format' => 'uuid',
        'x-ferry-reference' => true,
        'minLength' => 36,
        'maxLength' => 36,
    ]))]]);
    $expected = $replica->fresh()->connector->config;
    $seen = [];
    $origin = Mockery::mock(FakeOrigin::class)->makePartial();
    $origin
        ->shouldReceive('create')
        ->andReturnUsing(function (Connector $connector) use (&$seen) {
            $seen[$connector->name] = $connector->config;
        });
    ferry_use_origin($origin);
    ferry_drain_connectors();
    expect($seen['ferry-sink-test-replica'])
        ->toBe($expected)
        ->and($replica->fresh()->connector->applied_config)
        ->toBe($expected);
});

it('keeps pending state and retries after an external failure', function () {
    $replica = TestCase::makeReplica();
    $sinkId = $replica->connector_id;
    $origin = Mockery::mock(FakeOrigin::class)->makePartial();
    $origin
        ->shouldReceive('create')
        ->with(Mockery::on(fn (Connector $c) => $c->id !== $sinkId))
        ->andReturnNull();
    $origin
        ->shouldReceive('create')
        ->with(Mockery::on(fn (Connector $c) => $c->id === $sinkId))
        ->once()
        ->andThrow(new RuntimeException('Connect unavailable'));
    ferry_use_origin($origin);
    $job = new SynchronizeConnectorsJob($replica->upstream_id);
    expect(fn () => $job->handle(app(ConnectorManager::class)))
        ->toThrow(ConnectorOperationFailed::class, 'Connect unavailable');
    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Pending)
        ->and($replica->fresh()->connector->applied_config)
        ->toBeNull()
        ->and($replica->fresh()->connector->error)
        ->toBe('Connect unavailable');
    $recovered = new FakeOrigin;
    ferry_use_origin($recovered);
    $job->handle(app(ConnectorManager::class));
    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Active)
        ->and($replica->fresh()->connector->error)
        ->toBeNull()
        ->and($recovered->operations)
        ->toContain(['create', 'ferry-sink-test-replica']);
});

it('preserves pause while queued updates are processed', function () {
    $replica = TestCase::makeReplica();
    $replica->pause();
    ferry_drain_connectors();
    $replica
        ->refresh()
        ->update(['schema' => [new FieldMapping('_idrref', 'renamed_id', JsonSchema::fromArray([
            'type' => 'string',
            'format' => 'uuid',
            'x-ferry-reference' => true,
            'minLength' => 36,
            'maxLength' => 36,
        ]))]]);
    ferry_drain_connectors();
    expect($replica->fresh()->status)
        ->toBe(ReplicaStatus::Pause)
        ->and($replica->fresh()->connector->status)
        ->toBe(ConnectorStatus::Paused)
        ->and($replica->fresh()->upstream->connector->status)
        ->toBe(ConnectorStatus::Running);
});

it('removes sinks before dropping tables and keeps sibling capture', function (bool $keepSibling) {
    $replica = TestCase::makeReplica();
    $upstream = $replica->upstream;
    $sibling = $keepSibling ? TestCase::makeReplica(['code' => 'sibling', 'upstream_id' => $upstream->id]) : null;
    ferry_drain_connectors();
    $replica->refresh()->delete();
    expect(Schema::hasTable($replica->destination))
        ->toBeTrue()
        ->and(Replica::query()->find($replica->id))
        ->toBeNull()
        ->and(Replica::withTrashed()->find($replica->id))
        ->not->toBeNull();
    ferry_drain_connectors();
    expect(Schema::hasTable($replica->destination))
        ->toBeFalse()
        ->and(Replica::withTrashed()->find($replica->id))
        ->toBeNull()
        ->and($upstream->fresh()->connector->status)
        ->toBe($keepSibling ? ConnectorStatus::Running : ConnectorStatus::Paused)
        ->and($upstream->fresh()->connector->config['replicas'])
        ->toBe($sibling ? [$sibling->id] : []);
})->with([true, false]);

it('retains deletion context after failure and completes on retry', function () {
    $replica = TestCase::makeReplica();
    ferry_drain_connectors();
    $replica->refresh()->delete();
    $origin = Mockery::mock(FakeOrigin::class)->makePartial();
    $origin->shouldReceive('remove')->once()->andThrow(new RuntimeException('Deletion unavailable'));
    ferry_use_origin($origin);
    $job = new SynchronizeConnectorsJob($replica->upstream_id);
    expect(fn () => $job->handle(app(ConnectorManager::class)))->toThrow(ConnectorOperationFailed::class);
    expect(Replica::withTrashed()->find($replica->id)?->connector_id)
        ->not
        ->toBeNull()
        ->and(Schema::hasTable($replica->destination))
        ->toBeTrue();
    ferry_use_origin(new FakeOrigin);
    $job->handle(app(ConnectorManager::class));
    expect(Replica::withTrashed()->find($replica->id))
        ->toBeNull()
        ->and(Schema::hasTable($replica->destination))
        ->toBeFalse();
});

it('removes upstream resources in sink then source order', function () {
    $origin = app(OriginFactory::class)->create('kafka');
    $replica = TestCase::makeReplica();
    $upstream = $replica->upstream;
    ferry_drain_connectors();
    $origin->operations = [];
    $upstream->refresh()->delete();
    ferry_drain_connectors();
    expect($origin->operations)
        ->toBe([
            ['remove', 'ferry-sink-test-replica'],
            ['remove', 'ferry-source-test-upstream'],
        ])
        ->and(Upstream::withTrashed()->count())
        ->toBe(0)
        ->and(Replica::withTrashed()->count())
        ->toBe(0)
        ->and(Connector::query()->count())
        ->toBe(0)
        ->and(Schema::hasTable($replica->destination))
        ->toBeFalse();
});

it('updates source credentials through the queue', function () {
    $replica = TestCase::makeReplica();
    ferry_drain_connectors();
    $upstream = $replica->upstream->fresh();
    $upstream->update(['config' => [...$upstream->config, 'password' => 'changed-secret']]);
    expect($upstream->fresh()->connector->applied_config['credentials']['password'])->toBe('secret');
    ferry_drain_connectors();
    expect($upstream->fresh()->connector->applied_config['credentials']['password'])->toBe('changed-secret');
});

it('preserves desired state when enqueueing fails and can retry deployment', function () {
    Schema::rename('jobs', 'unavailable_jobs');
    expect(TestCase::makeReplica(...))->toThrow(\Illuminate\Database\QueryException::class);
    expect(Replica::query()->count())
        ->toBe(1)
        ->and(Connector::query()->count())
        ->toBe(2)
        ->and(Schema::hasTable('ferry_replica_test-replica'))
        ->toBeTrue();

    Schema::rename('unavailable_jobs', 'jobs');
    $replica = Replica::query()->sole();
    app(ConnectorManager::class)->restart($replica);
    ferry_drain_connectors();

    expect($replica->fresh()->status)->toBe(ReplicaStatus::Active);
});

it('retains removed sinks when refreshing the remaining source fails', function () {
    $replica = TestCase::makeReplica();
    ferry_drain_connectors();
    $replica->refresh()->delete();
    $origin = Mockery::mock(FakeOrigin::class)->makePartial();
    $origin->shouldReceive('update')->once()->andThrow(new RuntimeException('Source unavailable'));
    ferry_use_origin($origin);
    $job = new SynchronizeConnectorsJob($replica->upstream_id);
    expect(fn () => $job->handle(app(ConnectorManager::class)))->toThrow(ConnectorOperationFailed::class);
    expect(Replica::withTrashed()->find($replica->id)?->connector_id)
        ->not
        ->toBeNull()
        ->and(Schema::hasTable($replica->destination))
        ->toBeTrue();
    $retry = new FakeOrigin;
    ferry_use_origin($retry);
    $job->handle(app(ConnectorManager::class));
    expect($retry->operations)
        ->toContain(['remove', 'ferry-sink-test-replica'])
        ->and(Replica::withTrashed()->find($replica->id))
        ->toBeNull()
        ->and($replica->upstream->fresh()->connector->config['replicas'])
        ->toBe([]);
});

it('uses the application queue when Ferry models use another database', function (bool $commit) {
    config([
        'phpinnacle-ferry.connection' => 'ferry-test',
        'database.connections.ferry-test' => config('database.connections.sqlite'),
    ]);
    $migration = require __DIR__ . '/../../database/migrations/create_ferry_tables.php';
    app(\Illuminate\Database\Migrations\Migrator::class)->usingConnection(
        $migration->getConnection(),
        $migration->up(...),
    );
    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream);
    $database = $upstream->getConnection();
    $database->beginTransaction();
    TestCase::makeReplica(['upstream_id' => $upstream->id]);

    expect(DB::connection('sqlite')->table('jobs')->count())
        ->toBe(0)
        ->and($database->getSchemaBuilder()->hasTable('jobs'))
        ->toBeFalse();

    $commit ? $database->commit() : $database->rollBack();

    expect(DB::connection('sqlite')->table('jobs')->count())
        ->toBe($commit ? 2 : 0)
        ->and(Replica::query()->count())
        ->toBe($commit ? 1 : 0);
})->with(['commit' => true, 'rollback' => false]);
