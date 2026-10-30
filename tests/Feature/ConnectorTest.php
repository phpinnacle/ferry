<?php

use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema as FormSchema;
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
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Actions\RefreshConnectorStatusesAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\StartReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Schemas\ReplicaForm;
use PHPinnacle\Ferry\Resources\Replicas\Tables\ReplicaTable;
use PHPinnacle\Ferry\Resources\Upstreams\Schemas\UpstreamForm;
use PHPinnacle\Ferry\Resources\Upstreams\Tables\UpstreamTable;
use PHPinnacle\Ferry\Services\DestinationFactory;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fakes\Origin as FakeOrigin;
use PHPinnacle\Ferry\Tests\Fixtures\SchemaHost;
use PHPinnacle\Ferry\Tests\TestCase;

use function PHPinnacle\Ferry\Tests\Fakes\customers_destination;

require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../Fakes/CustomersDestination.php';
require_once __DIR__ . '/../Fixtures/SchemaHost.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('offers one status refresh action before creation on both lists and no per-record status actions', function () {
    $livewire = Mockery::mock(HasTable::class);
    $replicas = ReplicaTable::configure(Table::make($livewire));
    $upstreams = UpstreamTable::configure(Table::make($livewire));

    expect(array_map(fn ($action) => $action->getName(), $replicas->getHeaderActions()))
        ->toBe(['refresh_connector_statuses', 'create'])
        ->and(array_map(fn ($action) => $action->getName(), $upstreams->getHeaderActions()))
        ->toBe(['refresh_connector_statuses', 'create'])
        ->and($replicas->getAction('check_sink_status'))
        ->toBeNull()
        ->and($upstreams->getAction('check_source_status'))
        ->toBeNull()
        ->and($upstreams->getAction('start_source_connector'))
        ->toBeNull();
});

it('shows saved connector errors only in failed status tooltips', function (
    ?ConnectorStatus $status,
    ?string $error,
    ?string $tooltip,
    string $tableClass,
    string $modelClass,
) {
    $record = new $modelClass;
    $record->setRelation(
        'connector',
        $status === null
            ? null
            : new Connector()->forceFill([
                'status' => $status,
                'error' => $error,
            ]),
    );
    $table = $tableClass::configure(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumn('connector.status')->record($record)->getTooltip())->toBe($tooltip);
})->with([
    'failed with error' => [
        ConnectorStatus::Failed,
        "Task failed\nConnection refused",
        "Task failed\nConnection refused",
    ],
    'failed without error' => [ConnectorStatus::Failed, null, null],
    'running with old error' => [ConnectorStatus::Running, 'Old failure', null],
    'paused with old error' => [ConnectorStatus::Paused, 'Old failure', null],
    'unknown with old error' => [ConnectorStatus::Unknown, 'Old failure', null],
    'missing connector' => [null, null, null],
])->with([
    'upstreams' => [UpstreamTable::class, Upstream::class],
    'replicas' => [ReplicaTable::class, Replica::class],
]);

it('shows connector error callouts only for failed connectors with a saved error', function (
    ?ConnectorStatus $status,
    ?string $error,
    bool $visible,
    string $formClass,
    string $modelClass,
) {
    $record = $modelClass === Replica::class
        ? TestCase::makeReplica(['mapping' => []])
        : TestCase::makeUpstream();
    $record->setRelation(
        'connector',
        $status === null
            ? null
            : new Connector()->forceFill([
                'status' => $status,
                'error' => $error,
            ]),
    );
    $schema = $formClass::configure(FormSchema::make(SchemaHost::make())->model($record)->operation('edit'));
    $callout = $schema->getComponent('connectorError', withHidden: true);

    expect($callout)->toBeInstanceOf(Callout::class);
    expect($callout->isVisible())->toBe($visible);
    if ($visible) {
        expect($callout->getDescription())->toBe($error);
        expect($callout->toHtml())->toContain(e($error))->not->toContain('<script>');
    }
})->with([
    'failed with error' => [ConnectorStatus::Failed, "Task failed\n<script>alert('trace')</script>", true],
    'failed without error' => [ConnectorStatus::Failed, null, false],
    'running with old error' => [ConnectorStatus::Running, 'Old failure', false],
    'paused with old error' => [ConnectorStatus::Paused, 'Old failure', false],
    'missing connector' => [null, null, false],
])->with([
    'upstreams' => [UpstreamForm::class, Upstream::class],
    'replicas' => [ReplicaForm::class, Replica::class],
]);

it('omits connector error callouts from forms without a saved owner', function (
    string $formClass,
    string $modelClass,
    string $method,
) {
    $schema = $formClass::$method(FormSchema::make(SchemaHost::make())->model($modelClass)->operation('create'));

    expect($schema->getComponent('connectorError', withHidden: true)?->isVisible() ?? false)->toBeFalse();
})->with([
    'upstreams' => [UpstreamForm::class, Upstream::class, 'configure'],
    'replicas' => [ReplicaForm::class, Replica::class, 'creation'],
]);

it('refreshes every connector and continues after an external status check fails', function (bool $fails) {
    $replica = TestCase::makeReplica();
    TestCase::makeReplica(['code' => 'second-replica', 'upstream_id' => $replica->upstream_id]);
    $withoutConnector = TestCase::makeUpstream(['code' => 'no-connector']);
    $connectors = Connector::query()->orderBy('id')->get();
    $failed = $connectors[1];
    $checked = [];
    $origin = Mockery::mock(FakeOrigin::class)->makePartial();
    $origin
        ->shouldReceive('status')
        ->andReturnUsing(function (Connector $connector) use ($fails, $failed, &$checked) {
            $checked[] = $connector->id;
            if ($fails && $connector->is($failed)) {
                throw new RuntimeException('Connect unavailable');
            }

            $connector->error = 'Task diagnostic';

            return ConnectorStatus::Paused;
        });
    $origins = Mockery::mock(OriginFactory::class);
    $origins->shouldReceive('create')->with('kafka')->times(3)->andReturn($origin);
    app()->instance(OriginFactory::class, $origins);
    Queue::fake();

    RefreshConnectorStatusesAction::table()->call();

    expect($checked)
        ->toEqualCanonicalizing($connectors->modelKeys())
        ->and($withoutConnector->fresh()->connector)
        ->toBeNull()
        ->and(Connector::query()->count())
        ->toBe(3);
    foreach ($connectors as $connector) {
        $connector->refresh();
        if ($fails && $connector->is($failed)) {
            expect($connector->checked_at)->toBeNull()->and($connector->status)->toBeNull();
        } else {
            expect($connector->status)
                ->toBe(ConnectorStatus::Paused)
                ->and($connector->error)
                ->toBe('Task diagnostic')
                ->and($connector->checked_at)
                ->not->toBeNull();
        }
    }

    Queue::assertNothingPushed();
    $notification = Notification::make();
    Notification::assertNotified(
        $fails
            ? $notification
                ->title(__('phpinnacle-ferry::resources.replica.messages.status_refresh_failed'))
                ->body($failed->name . ': Connect unavailable')
                ->danger()
            : $notification->title(__('phpinnacle-ferry::resources.replica.messages.statuses_refreshed'))->success(),
    );
})->with(['all succeed' => false, 'partial failure' => true]);

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

it('filters replicas by status, upstream and destination', function () {
    app(DestinationFactory::class)->register(customers_destination());
    $dynamic = TestCase::makeReplica(['mapping' => []]);
    $static = TestCase::makeReplica([
        'upstream_id' => $dynamic->upstream_id,
        'code' => 'static-replica',
        'type' => DestinationType::Static,
        'destination' => 'customers',
        'mapping' => [],
    ]);
    $static->forceFill(['status' => ReplicaStatus::Pause])->saveQuietly();
    $otherUpstream = TestCase::makeUpstream(['code' => 'other-upstream']);
    TestCase::makeItem($otherUpstream);
    $other = TestCase::makeReplica([
        'upstream_id' => $otherUpstream->id,
        'code' => 'other-replica',
        'mapping' => [],
    ]);
    $table = ReplicaTable::configure(Table::make(Mockery::mock(HasTable::class)))->query(Replica::query());
    $status = $table->getFilter('status');
    $upstream = $table->getFilter('upstream');
    $destination = $table->getFilter('destination');

    expect($destination->getOptions())
        ->toBe([
            'customers' => 'Customers',
            $other->destination => $other->destination,
            $dynamic->destination => $dynamic->destination,
        ])
        ->and($status->apply(Replica::query(), ['value' => ReplicaStatus::Pause->value])->pluck('id')->all())
        ->toBe([$static->id])
        ->and($upstream->apply(Replica::query(), ['value' => $otherUpstream->id])->pluck('id')->all())
        ->toBe([$other->id])
        ->and($destination->apply(Replica::query(), ['value' => $dynamic->destination])->pluck('id')->all())
        ->toBe([$dynamic->id]);

    $query = $upstream->apply(Replica::query(), ['value' => $dynamic->upstream_id]);
    $query = $destination->apply($query, ['value' => 'customers']);
    expect($status->apply($query, ['value' => ReplicaStatus::Pause->value])->pluck('id')->all())
        ->toBe([$static->id]);
});

it('shows exactly one start or pause control for the replica state', function (
    ReplicaStatus $status,
    ?ConnectorStatus $connectorStatus,
    string $visibleAction,
) {
    $replica = new Replica;
    $replica->status = $status;
    $replica->setRelation(
        'connector',
        $connectorStatus !== null
            ? new Connector()->forceFill(['status' => $connectorStatus])
            : null,
    );
    $table = ReplicaTable::configure(Table::make(Mockery::mock(HasTable::class)));
    $controls = collect($table->getRecordActions())
        ->filter(fn ($action) => in_array(
            $action->getName(),
            ['start_replica', 'activate_replica', 'pause_replica', 'restart_replica'],
            true,
        ))
        ->filter(fn ($action) => $action->record($replica)->authorize(true)->isVisible())
        ->map(fn ($action) => $action->getName())
        ->values()
        ->all();

    expect($controls)->toBe([$visibleAction]);
})->with([
    'new replica' => [ReplicaStatus::Pending, null, 'start_replica'],
    'running replica' => [ReplicaStatus::Active, ConnectorStatus::Running, 'pause_replica'],
    'externally paused connector' => [ReplicaStatus::Active, ConnectorStatus::Paused, 'start_replica'],
    'failed connector' => [ReplicaStatus::Active, ConnectorStatus::Failed, 'start_replica'],
    'paused replica with failed connector' => [ReplicaStatus::Pause, ConnectorStatus::Failed, 'start_replica'],
    'pause awaiting synchronization' => [ReplicaStatus::Pause, ConnectorStatus::Running, 'start_replica'],
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
        ->and(StartReplicaAction::table()->record($replica)->authorize(true)->isVisible())
        ->toBeTrue();

    $sink->recordStatus(ConnectorStatus::Running);
    $replica->forceFill(['status' => ReplicaStatus::Active])->saveQuietly();

    expect(StartReplicaAction::table()->record($replica->fresh())->authorize(true)->isVisible())->toBeFalse();
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
