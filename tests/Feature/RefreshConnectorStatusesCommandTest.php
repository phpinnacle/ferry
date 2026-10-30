<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fakes\Origin;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('refreshes all saved connectors from Artisan and continues after a status request fails', function (bool $fails) {
    $replica = TestCase::makeReplica();
    TestCase::makeReplica(['code' => 'second-replica', 'upstream_id' => $replica->upstream_id]);
    $withoutConnector = TestCase::makeUpstream(['code' => 'no-connector']);
    $connectors = Connector::query()->orderBy('id')->get();
    $failed = $connectors[1];
    $failed->recordStatus(ConnectorStatus::Running, 'Previous diagnostic');
    $checkedAt = $failed->checked_at;
    $checked = [];
    $origin = Mockery::mock(Origin::class)->makePartial();
    $origin
        ->shouldReceive('status')
        ->andReturnUsing(function (Connector $connector) use ($fails, $failed, &$checked) {
            $checked[] = $connector->id;
            if ($fails && $connector->is($failed)) {
                throw new RuntimeException('Connect unavailable');
            }

            $connector->error = 'Task diagnostic';

            return ConnectorStatus::Failed;
        });
    $origins = Mockery::mock(OriginFactory::class);
    $origins->shouldReceive('create')->with('kafka')->times(3)->andReturn($origin);
    app()->instance(OriginFactory::class, $origins);
    Queue::fake();

    $command = $this->artisan('ferry:refresh');
    if ($fails) {
        $command->expectsOutput($failed->name . ': Connect unavailable');
    }

    $command->expectsOutput('Connector status refresh completed.')->assertSuccessful()->run();

    expect($checked)
        ->toEqualCanonicalizing($connectors->modelKeys())
        ->and($withoutConnector->fresh()->connector)
        ->toBeNull()
        ->and(Connector::query()->count())
        ->toBe(3);
    foreach ($connectors as $connector) {
        $connector->refresh();
        if ($fails && $connector->is($failed)) {
            expect($connector->status)
                ->toBe(ConnectorStatus::Running)
                ->and($connector->error)
                ->toBe('Previous diagnostic')
                ->and($connector->checked_at->equalTo($checkedAt))
                ->toBeTrue();
        } else {
            expect($connector->status)
                ->toBe(ConnectorStatus::Failed)
                ->and($connector->error)
                ->toBe('Task diagnostic')
                ->and($connector->checked_at)
                ->not->toBeNull();
        }
    }

    Queue::assertNothingPushed();
})->with(['all requests succeed' => false, 'partial request failure' => true]);

it('succeeds without creating connectors when none are saved', function () {
    TestCase::makeUpstream();
    Queue::fake();

    $this
        ->artisan('ferry:refresh')
        ->expectsOutput('Connector status refresh completed.')
        ->assertSuccessful();

    expect(Connector::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('skips unexpected connector check failures and still exits successfully', function () {
    TestCase::makeReplica();
    $connectors = Connector::query()->orderBy('id')->get();
    $manager = Mockery::mock(ConnectorManager::class)->makePartial();
    $manager
        ->shouldReceive('checkStatus')
        ->once()
        ->with(Mockery::on(fn ($connector) => $connector->is($connectors[0])))
        ->andThrow(new RuntimeException('Origin unavailable'));
    $manager
        ->shouldReceive('checkStatus')
        ->once()
        ->with(Mockery::on(fn ($connector) => $connector->is($connectors[1])))
        ->andReturnUsing(fn (Connector $connector) => $connector->recordStatus(ConnectorStatus::Running));
    app()->instance(ConnectorManager::class, $manager);
    Queue::fake();

    $this
        ->artisan('ferry:refresh')
        ->expectsOutput($connectors[0]->name . ': Origin unavailable')
        ->expectsOutput('Connector status refresh completed.')
        ->assertSuccessful();

    expect($connectors[0]->fresh()->checked_at)
        ->toBeNull()
        ->and($connectors[1]->fresh()->status)
        ->toBe(ConnectorStatus::Running);
    Queue::assertNothingPushed();
});
