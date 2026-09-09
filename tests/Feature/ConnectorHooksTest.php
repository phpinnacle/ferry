<?php

use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPinnacle\Ferry\Data\FieldMapping;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Enums\SyncStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
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
});

it('deletes the sink connector when a previously activated synchronization is removed', function () {
    $sync = TestCase::makeSync([], [
        'status' => SyncStatus::Active,
        'sink_connector_status' => ConnectorStatus::Running,
    ]);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('delete')
        ->once()
        ->with(Mockery::on(fn (Sync $argument) => $argument->is($sync)));
    $this->app->instance(ConnectorManager::class, $manager);

    $sync->delete();
});

it('does not call the connector manager when deleting a connection whose source connector was never created', function () {
    $manager = Mockery::mock(ConnectorManager::class);
    $manager->shouldNotReceive('deleteSource');
    $this->app->instance(ConnectorManager::class, $manager);

    $connection = TestCase::makeConnection();

    $connection->delete();
});

it('deletes the source connector when a connection with an active source connector is removed', function () {
    $connection = TestCase::makeConnection([], ['source_connector_status' => ConnectorStatus::Running]);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('deleteSource')
        ->once()
        ->with(Mockery::on(fn (Connection $argument) => $argument->is($connection)));
    $this->app->instance(ConnectorManager::class, $manager);

    $connection->delete();
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
    ], ['status' => SyncStatus::Active, 'sink_connector_status' => ConnectorStatus::Running]);

    /** @var ConnectorManager&MockInterface $manager */
    $manager = Mockery::mock(ConnectorManager::class);
    $manager
        ->shouldReceive('pause')
        ->once()
        ->with(Mockery::on(fn (Sync $argument) => $argument->is($sync)), false);
    $this->app->instance(ConnectorManager::class, $manager);

    app(SyncReviewer::class)->review($connection);
});
