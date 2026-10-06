<?php

use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Fields\ScalarField;
use PHPinnacle\Rosetta\Fields\StringField;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('does not call the connector manager when deleting a synchronization that was never activated', function () {
    $manager = Mockery::mock(ConnectorManager::class);
    $manager->shouldNotReceive('delete');
    $this->app->instance(ConnectorManager::class, $manager);

    $sync = TestCase::makeSync();

    $sync->delete();

    expect(Connector::query()->count())->toBe(0);
});

it('asks the connector manager to delete the sink when its synchronization is removed', function () {
    $sync = TestCase::makeSync([], [
        'status' => SyncStatus::Active,
    ]);
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-' . $sync->code])
        ->recordStatus(ConnectorStatus::Running);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('delete')
        ->once()
        ->with(Mockery::on(fn (Sync $argument) => $argument->is($sync)));
    $this->app->instance(ConnectorManager::class, $manager);

    $sync->delete();

    expect(Sync::query()->find($sync->id))->toBeNull();
});

it('does not call the connector manager when deleting a connection whose source connector was never created', function () {
    $manager = Mockery::mock(ConnectorManager::class);
    $manager->shouldNotReceive('deleteSource');
    $this->app->instance(ConnectorManager::class, $manager);

    $connection = TestCase::makeConnection();

    $connection->delete();

    expect(Connector::query()->count())->toBe(0);
});

it('asks the connector manager to delete the source when its connection is removed', function () {
    $connection = TestCase::makeConnection();
    TestCase::makeConnector($connection, ['name' => 'ferry-source-' . $connection->code])
        ->recordStatus(ConnectorStatus::Running);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('deleteSource')
        ->once()
        ->with(Mockery::on(fn (Connection $argument) => $argument->is($connection)));
    $this->app->instance(ConnectorManager::class, $manager);

    $connection->delete();

    expect(Connection::query()->find($connection->id))->toBeNull();
});

it('refreshes an existing source connector after changing connection credentials', function () {
    $connection = TestCase::makeConnection();
    TestCase::makeConnector($connection, ['name' => 'ferry-source-' . $connection->code]);
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('refreshSource')
        ->once()
        ->with(Mockery::on(fn (Connection $argument) => $argument->is($connection)));
    $this->app->instance(ConnectorManager::class, $manager);

    $connection->update(['password' => 'changed-secret']);
});

it('pauses the sink connector through the connector manager once it has been activated', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    TestCase::makeMetadata($connection);

    $sync = TestCase::makeSync([
        'connection_id' => $connection->id,
        'source' => 'object-0',
        'schema' => [
            new FieldMapping('_idrref', 'external_id', new ScalarField(FieldType::Id)),
            new FieldMapping('title', 'title', new StringField(length: 100, fixed: false)),
        ],
    ], ['status' => SyncStatus::Active]);
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-' . $sync->code])
        ->recordStatus(ConnectorStatus::Running);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('pause')
        ->once()
        ->with(Mockery::on(fn (Sync $argument) => $argument->is($sync)), false);
    $this->app->instance(ConnectorManager::class, $manager);

    $connection->metadata()->update(['revision' => $connection->draftRevision()]);
    $connection->publishStructure();
});

it('removes local sink connectors when their connection cascades synchronization deletion', function () {
    $sync = TestCase::makeSync();
    $connection = $sync->connection;
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-' . $sync->code]);
    $manager = Mockery::mock(ConnectorManager::class);
    $manager->shouldNotReceive('delete');
    $manager->shouldNotReceive('deleteSource');
    $this->app->instance(ConnectorManager::class, $manager);

    $connection->delete();

    expect(Sync::query()->find($sync->id))->toBeNull()->and(Connector::query()->count())->toBe(0);
});

it('manages owners and reviews unconfigured synchronizations without Kafka transport', function () {
    config([
        'phpinnacle-ferry.kafka_connect.base_uri' => null,
        'phpinnacle-ferry.kafka_connect.signal.bootstrap_servers' => null,
    ]);
    $sync = TestCase::makeSync();
    $connection = $sync->connection;

    $connection->forceFill(['generation' => 1, 'status' => StructureStatus::Ready])->save();

    expect($sync->fresh()->status)->toBe(SyncStatus::Pause);

    $connection->update(['password' => 'changed-secret']);
    $sync->delete();
    $connection->delete();

    expect(Connection::query()->count())
        ->toBe(0)
        ->and(Sync::query()->count())
        ->toBe(0)
        ->and(Connector::query()->count())
        ->toBe(0);
});

it('resumes an automatic pause but preserves a manual pause after saving', function (bool $manually, bool $renamed) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $sync = TestCase::makeSync(['connection_id' => $connection->id]);
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);
    $sync->pause(manually: $manually);

    $connectors = Mockery::mock(ConnectorManager::class);

    if ($manually) {
        $connectors->shouldNotReceive('activate');
    } else {
        $connectors
            ->shouldReceive('activate')
            ->once()
            ->with($sync)
            ->andReturnUsing(fn (Sync $sync) => $sync->activate());
    }

    $this->app->instance(ConnectorManager::class, $connectors);
    $sync->update(['name' => $renamed ? 'Renamed' : $sync->name]);

    expect($sync->fresh()->name)
        ->toBe($renamed ? 'Renamed' : 'Test synchronization')
        ->and($sync->fresh()->status)
        ->toBe($manually ? SyncStatus::Pause : SyncStatus::Active)
        ->and($sync->fresh()->is_paused)
        ->toBe($manually);
})->with([
    'manual pause' => [true, true],
    'automatic pause' => [false, true],
    'unchanged mapping' => [false, false],
]);

it('updates active connectors after commit and discards the update on rollback', function (bool $commit) {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);
    TestCase::makeMetadata($connection);
    $sync = TestCase::makeSync(['connection_id' => $connection->id], ['status' => SyncStatus::Active]);
    TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);
    $calls = 0;
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('activate')
        ->times($commit ? 1 : 0)
        ->with($sync)
        ->andReturnUsing(function (Sync $sync) use (&$calls) {
            expect($sync->getConnection()->transactionLevel())->toBe(0);
            $calls++;
            $sync->activate();
        });
    $this->app->instance(ConnectorManager::class, $manager);
    $database = $sync->getConnection();
    $database->beginTransaction();
    $sync->update([
        'name' => 'Renamed',
        'schema' => [new FieldMapping('_idrref', 'reference_id', new ScalarField(FieldType::Id))],
    ]);

    expect($calls)->toBe(0);

    $commit ? $database->commit() : $database->rollBack();

    expect($calls)
        ->toBe($commit ? 1 : 0)
        ->and($sync->fresh()->name)
        ->toBe($commit ? 'Renamed' : 'Test synchronization')
        ->and($sync->fresh()->schema[0]->column)
        ->toBe($commit ? 'reference_id' : 'external_id')
        ->and($database->getSchemaBuilder()->hasColumn($sync->destination, 'reference_id'))
        ->toBe($commit)
        ->and($database->getSchemaBuilder()->hasColumn($sync->destination, 'external_id'))
        ->toBe(!$commit);
})->with(['commit' => [true], 'rollback' => [false]]);
