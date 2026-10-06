<?php

use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Resources\Connections\Tables\ConnectionTable;
use PHPinnacle\Ferry\Resources\Syncs\Actions\RestartSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\Tables\SyncTable;
use PHPinnacle\Ferry\Services\Connectors\ConnectorManager;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('encrypts the JSON configuration and excludes it from model serialization', function () {
    $connection = TestCase::makeConnection();
    $config = ['database.hostname' => 'source.internal', 'database.password' => 'connector-secret'];
    $connector = TestCase::makeConnector($connection, ['name' => 'ferry-source-test-connection']);
    $connector->recordConfig($config);

    $stored = DB::table('connectors')->where('id', $connector->id)->value('config');

    expect($stored)
        ->not->toContain('connector-secret')->and(json_decode(
            Crypt::decryptString($stored),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe($config)->and($connector->fresh()->config)->toBe($config)->and($connector->fresh()->toArray())
        ->not->toHaveKey('config')->and($connection->fresh()->load('connector')->toArray()['connector'])
        ->not->toHaveKey('config');
});

it('records status independently of the source and synchronization lifecycle', function () {
    $sync = TestCase::makeSync();
    $source = TestCase::makeConnector($sync->connection, ['name' => 'ferry-source-test-connection']);
    $sink = TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);
    Event::fake(['eloquent.updated: ' . Connection::class, 'eloquent.updated: ' . Sync::class]);

    $source->recordStatus(ConnectorStatus::Running);
    $sink->recordStatus(ConnectorStatus::Failed, 'Failed task');

    expect($sync->fresh()->connection->connector?->status)
        ->toBe(ConnectorStatus::Running)
        ->and($sync->fresh()->connector?->error)
        ->toBe('Failed task')
        ->and($sink->fresh()->checked_at)
        ->not
        ->toBeNull()
        ->and($source->fresh()->connection?->is($sync->connection))
        ->toBeTrue()
        ->and($sink->fresh()->sync?->is($sync))
        ->toBeTrue();

    $sink->recordStatus(ConnectorStatus::Running);

    expect($sink->fresh()->error)->toBeNull();
    Event::assertNotDispatched('eloquent.updated: ' . Connection::class);
    Event::assertNotDispatched('eloquent.updated: ' . Sync::class);
});

it('uses the configured Ferry database for connectors and their owners', function () {
    config([
        'phpinnacle-ferry.connection' => 'ferry-test',
        'database.connections.ferry-test' => config('database.connections.sqlite'),
    ]);
    $migration = require __DIR__ . '/../../database/migrations/create_ferry_tables.php';
    app(Migrator::class)->usingConnection($migration->getConnection(), $migration->up(...));

    $connection = TestCase::makeConnection();
    $connector = TestCase::makeConnector($connection, ['name' => 'ferry-source-test-connection']);
    $connector->recordStatus(ConnectorStatus::Running);

    expect($connection->fresh()->connector?->is($connector))
        ->toBeTrue()
        ->and(Connector::query()->count())
        ->toBe(1)
        ->and(DB::connection('sqlite')->table('connectors')->count())
        ->toBe(0);
});

it('rolls back and reapplies the package tables together', function () {
    $migration = require __DIR__ . '/../../database/migrations/create_ferry_tables.php';
    $migration->down();

    foreach (['connectors', 'syncs', 'connection_metadata', 'connections'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    $migration->up();
    $sync = TestCase::makeSync();
    $connector = TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);

    expect($sync->fresh()->connector?->is($connector))->toBeTrue();
});

it('displays and sorts connector statuses through the owner tables', function () {
    $sync = TestCase::makeSync();
    $connection = $sync->connection;
    $source = TestCase::makeConnector($connection, ['name' => 'ferry-source-test-connection']);
    $source->recordStatus(ConnectorStatus::Paused);
    $sink = TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);
    $sink->recordStatus(ConnectorStatus::Failed);

    $otherConnection = TestCase::makeConnection(['code' => 'other-connection']);
    TestCase::makeConnector($otherConnection, ['name' => 'ferry-source-other-connection'])
        ->recordStatus(ConnectorStatus::Running);
    $otherSync = TestCase::makeSync(['code' => 'other-sync', 'connection_id' => $connection->id]);
    TestCase::makeConnector($otherSync, ['name' => 'ferry-sink-other-sync'])->recordStatus(ConnectorStatus::Running);

    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $record) => $record->getKey());
    $sourceColumn = ConnectionTable::configure(Table::make($livewire))->getColumn(
        'connector.status',
    );
    $sinkColumn = SyncTable::configure(Table::make($livewire))->getColumn('connector.status');

    expect($sourceColumn->record($connection)->getState())
        ->toBe(ConnectorStatus::Paused)
        ->and($sinkColumn->record($sync)->getState())
        ->toBe(ConnectorStatus::Failed)
        ->and($sourceColumn->applySort(Connection::query())->pluck('id')->all())
        ->toBe([$connection->id, $otherConnection->id])
        ->and($sinkColumn->applySort(Sync::query())->pluck('id')->all())
        ->toBe([$sync->id, $otherSync->id])
        ->and(RestartSyncAction::table()->record($sync)->authorize(true)->isVisible())
        ->toBeTrue();

    $sink->recordStatus(ConnectorStatus::Running);

    expect(RestartSyncAction::table()->record($sync->fresh())->authorize(true)->isVisible())->toBeFalse();
});

it('clears connector references without deleting their owners when a connector is removed', function () {
    $sync = TestCase::makeSync();
    $connection = $sync->connection;
    $source = TestCase::makeConnector($connection, ['name' => 'ferry-source-test-connection']);
    $sink = TestCase::makeConnector($sync, ['name' => 'ferry-sink-test-sync']);

    expect($connection->fresh()->connector_id)->toBe($source->id)->and($sync->fresh()->connector_id)->toBe($sink->id);

    $source->delete();
    $sink->delete();

    expect($connection->fresh()->connector_id)
        ->toBeNull()
        ->and($sync->fresh()->connector_id)
        ->toBeNull()
        ->and(Connection::query()->count())
        ->toBe(1)
        ->and(Sync::query()->count())
        ->toBe(1);
});

it('checks connector status through both owner table actions', function (string $role, bool $fails) {
    $owner = $role === 'source' ? TestCase::makeConnection() : TestCase::makeSync();
    $connector = TestCase::makeConnector($owner, ['name' => 'ferry-' . $role . '-test']);
    $manager = Mockery::mock(ConnectorManager::class);
    $expectation = $manager->shouldReceive('checkStatus')->once()->with($owner);

    if ($fails) {
        $expectation->andThrow(new RuntimeException('Kafka Connect unavailable'));
    } else {
        $expectation->andReturnUsing(fn () => $connector->recordStatus(ConnectorStatus::Running));
    }

    $this->app->instance(ConnectorManager::class, $manager);
    $livewire = Mockery::mock(HasTable::class);
    $table = $role === 'source'
        ? ConnectionTable::configure(Table::make($livewire))
        : SyncTable::configure(Table::make($livewire));
    $action = $table->getAction('check_' . $role . '_status');
    $action->record($owner)->call();

    expect(session()->get('filament.notifications.0.title'))
        ->toBe($fails ? 'Kafka Connect unavailable' : ConnectorStatus::Running->getLabel())
        ->and(session()->get('filament.notifications.0.status'))
        ->toBe($fails ? 'danger' : null);
})->with(['source', 'sink'])->with([false, true]);
