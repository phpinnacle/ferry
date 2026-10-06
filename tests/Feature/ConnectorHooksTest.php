<?php

use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Services\SyncReviewer;
use PHPinnacle\Ferry\Tests\TestCase;
use PHPinnacle\Rosetta\Enums\FieldType;
use PHPinnacle\Rosetta\Enums\MetadataKind;
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

    ConnectionMetadata::create([
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
    ]);

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

    app(SyncReviewer::class)->review($connection);
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
