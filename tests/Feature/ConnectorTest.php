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
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Replicas\Actions\RestartReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Tables\ReplicaTable;
use PHPinnacle\Ferry\Resources\Upstreams\Tables\UpstreamTable;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('displays destination labels as badges without a type column', function (
    DestinationType $type,
    ?string $destination,
) {
    app(DestinationFactory::class)->register(customers_destination());
    $replica = TestCase::makeReplica(['type' => $type, 'destination' => $destination]);
    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $record) => $record->getKey());
    $table = ReplicaTable::configure(Table::make($livewire));
    $column = $table->getColumn('destination')->record($replica);

    expect($table->getColumn('type'))
        ->toBeNull()
        ->and($column->isBadge())
        ->toBeTrue()
        ->and($column->formatState($column->getState()))
        ->toBe($destination === 'customers' ? 'Customers' : $replica->destination);
})->with([
    'registered static destination' => [DestinationType::Static, 'customers'],
    'dynamic destination' => [DestinationType::Dynamic, null],
]);

it('encrypts the JSON configuration and excludes it from model serialization', function () {
    $upstream = TestCase::makeUpstream();
    $config = ['database.hostname' => 'source.internal', 'database.password' => 'connector-secret'];
    $connector = TestCase::makeConnector($upstream, ['name' => 'ferry-source-test-upstream']);
    $connector->recordConfig($config);
    $connector->markApplied();

    $stored = DB::table('connectors')->where('id', $connector->id)->value('config');
    $applied = DB::table('connectors')->where('id', $connector->id)->value('applied_config');

    expect($stored)
        ->not->toContain('connector-secret')->and(json_decode(
            Crypt::decryptString($stored),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe($config)->and($connector->fresh()->config)->toBe($config)->and($connector->fresh()->toArray())
        ->not->toHaveKey('config')->and($applied)
        ->not->toContain('connector-secret')->and(json_decode(
            Crypt::decryptString($applied),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))->toBe($config)->and($connector->fresh()->toArray())
        ->not->toHaveKey('applied_config')->and($upstream->fresh()->load('connector')->toArray()['connector'])
        ->not->toHaveKey('config');
});

it('records status independently of the source and replica lifecycle', function () {
    $replica = TestCase::makeReplica();
    $source = TestCase::makeConnector($replica->upstream, ['name' => 'ferry-source-test-upstream']);
    $sink = TestCase::makeConnector($replica, ['name' => 'ferry-sink-test-replica']);
    Event::fake(['eloquent.updated: ' . Upstream::class, 'eloquent.updated: ' . Replica::class]);

    $source->recordStatus(ConnectorStatus::Running);
    $sink->recordStatus(ConnectorStatus::Failed, 'Failed task');

    expect($replica->fresh()->upstream->connector?->status)
        ->toBe(ConnectorStatus::Running)
        ->and($replica->fresh()->connector?->error)
        ->toBe('Failed task')
        ->and($sink->fresh()->checked_at)
        ->not->toBeNull();

    $sink->recordStatus(ConnectorStatus::Running);

    expect($sink->fresh()->error)->toBeNull();
    Event::assertNotDispatched('eloquent.updated: ' . Upstream::class);
    Event::assertNotDispatched('eloquent.updated: ' . Replica::class);
});

it('uses the configured Ferry database for connectors and their owners', function () {
    config([
        'phpinnacle-ferry.connection' => 'ferry-test',
        'database.connections.ferry-test' => config('database.connections.sqlite'),
    ]);
    $migration = require __DIR__ . '/../../database/migrations/create_ferry_tables.php';
    app(Migrator::class)->usingConnection($migration->getConnection(), $migration->up(...));

    $upstream = TestCase::makeUpstream();
    TestCase::makeItem($upstream);
    $replica = TestCase::makeReplica(['upstream_id' => $upstream->id]);
    $connector = TestCase::makeConnector($upstream, ['name' => 'ferry-source-test-upstream']);
    $connector->recordStatus(ConnectorStatus::Running);

    expect($upstream->fresh()->connector?->is($connector))
        ->toBeTrue()
        ->and(Connector::query()->count())
        ->toBe(2)
        ->and($replica->fresh()->type)
        ->toBe(DestinationType::Dynamic)
        ->and(DB::connection('sqlite')->table('replicas')->count())
        ->toBe(0)
        ->and(DB::connection('sqlite')->table('connectors')->count())
        ->toBe(0);

    app(Migrator::class)->usingConnection($migration->getConnection(), $migration->down(...));

    expect(Schema::connection('ferry-test')->hasTable('upstreams'))
        ->toBeFalse()
        ->and(Schema::connection('sqlite')->hasTable('upstreams'))
        ->toBeTrue();
});

it('rolls back all Ferry tables and can install them again', function () {
    TestCase::makeReplica();
    $migration = require __DIR__ . '/../../database/migrations/create_ferry_tables.php';
    $migration->down();

    foreach (['replicas', 'structure_items', 'structures', 'upstreams', 'connectors'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasTable('jobs'))->toBeTrue();

    $migration->up();

    expect(TestCase::makeReplica(['code' => 'reinstalled'])->fresh()->upstream->structure)->not->toBeNull();
});

it('displays and sorts connector statuses through the owner tables', function () {
    $replica = TestCase::makeReplica();
    $upstream = $replica->upstream;
    $source = TestCase::makeConnector($upstream, ['name' => 'ferry-source-test-upstream']);
    $source->recordStatus(ConnectorStatus::Paused);
    $sink = TestCase::makeConnector($replica, ['name' => 'ferry-sink-test-replica']);
    $sink->recordStatus(ConnectorStatus::Failed);

    $otherUpstream = TestCase::makeUpstream(['code' => 'other-upstream']);
    TestCase::makeConnector($otherUpstream, ['name' => 'ferry-source-other-upstream'])
        ->recordStatus(ConnectorStatus::Running);
    $otherReplica = TestCase::makeReplica(['code' => 'other-replica', 'upstream_id' => $upstream->id]);
    TestCase::makeConnector($otherReplica, [
        'name' => 'ferry-sink-other-replica',
    ])->recordStatus(ConnectorStatus::Running);

    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $record) => $record->getKey());
    $sourceColumn = UpstreamTable::configure(Table::make($livewire))->getColumn(
        'connector.status',
    );
    $sinkColumn = ReplicaTable::configure(Table::make($livewire))->getColumn('connector.status');

    expect($sourceColumn->record($upstream)->getState())
        ->toBe(ConnectorStatus::Paused)
        ->and($sinkColumn->record($replica)->getState())
        ->toBe(ConnectorStatus::Failed)
        ->and($sourceColumn->applySort(Upstream::query())->pluck('id')->all())
        ->toBe([$upstream->id, $otherUpstream->id])
        ->and($sinkColumn->applySort(Replica::query())->pluck('id')->all())
        ->toBe([$replica->id, $otherReplica->id])
        ->and(RestartReplicaAction::table()->record($replica)->authorize(true)->isVisible())
        ->toBeTrue();

    $sink->recordStatus(ConnectorStatus::Running);

    expect(RestartReplicaAction::table()->record($replica->fresh())->authorize(true)->isVisible())->toBeFalse();
});

it('clears connector references without deleting their owners when a connector is removed', function () {
    $replica = TestCase::makeReplica();
    $upstream = $replica->upstream;
    $source = TestCase::makeConnector($upstream, ['name' => 'ferry-source-test-upstream']);
    $sink = TestCase::makeConnector($replica, ['name' => 'ferry-sink-test-replica']);

    expect($upstream->fresh()->connector_id)->toBe($source->id)->and($replica->fresh()->connector_id)->toBe($sink->id);

    $source->delete();
    $sink->delete();

    expect($upstream->fresh()->connector_id)
        ->toBeNull()
        ->and($replica->fresh()->connector_id)
        ->toBeNull()
        ->and(Upstream::query()->count())
        ->toBe(1)
        ->and(Replica::query()->count())
        ->toBe(1);
});
