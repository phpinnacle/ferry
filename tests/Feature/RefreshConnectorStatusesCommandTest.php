<?php

use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\Fakes\Origin;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('refreshes all saved connectors from Artisan and continues after a status request fails', function () {
    $replica = TestCase::makeReplica();
    TestCase::makeReplica(['code' => 'second-replica', 'upstream_id' => $replica->upstream_id]);
    $connectors = Connector::query()->orderBy('id')->get();
    $failed = $connectors[1];
    $failed->recordStatus(ConnectorStatus::Running, 'Previous diagnostic');
    $checkedAt = $failed->checked_at;
    $checked = [];
    $origin = Mockery::mock(Origin::class)->makePartial();
    $origin
        ->shouldReceive('status')
        ->andReturnUsing(function (Connector $connector) use ($failed, &$checked) {
            $checked[] = $connector->id;
            if ($connector->is($failed)) {
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
    $command->expectsOutput($failed->name . ': Connect unavailable');

    $command->expectsOutput('Connector status refresh completed.')->assertSuccessful()->run();

    expect($checked)
        ->toEqualCanonicalizing($connectors->modelKeys())
        ->and(Connector::query()->count())
        ->toBe(3);
    foreach ($connectors as $connector) {
        $connector->refresh();
        if ($connector->is($failed)) {
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
});

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
