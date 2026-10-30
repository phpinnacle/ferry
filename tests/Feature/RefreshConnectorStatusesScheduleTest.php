<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

it('schedules a direct method call at the configured interval in seconds', function (int $seconds) {
    config(['phpinnacle-ferry.scheduler.refresh' => $seconds]);
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn ($event) => $event->description === 'ferry:refresh-statuses',
    ));

    expect($events)->toHaveCount(1);
    $event = $events[0];

    $this->travelTo(now()->setTimestamp(0));
    expect($event->filtersPass(app()))->toBeTrue();
    $this->travel($seconds)->seconds();
    expect($event->filtersPass(app()))->toBeTrue();
    $this->travel(1)->seconds();
    expect($event->filtersPass(app()))->toBe($seconds === 1);

    $manager = Mockery::mock(ConnectorManager::class);
    $manager->shouldReceive('refreshStatuses')->once()->with(null);
    app()->instance(ConnectorManager::class, $manager);

    $event->run(app());
})->with([1, 7, 90]);

it('does not register a refresh task when the interval is non-positive', function (int $seconds) {
    config(['phpinnacle-ferry.scheduler.refresh' => $seconds]);

    expect(array_filter(
        app(Schedule::class)->events(),
        fn ($event) => $event->description === 'ferry:refresh-statuses',
    ))->toBeEmpty();
})->with([0, -1]);

it('refreshes saved connectors from the scheduled callback and reports individual failures', function () {
    Queue::fake();
    Exceptions::fake();
    TestCase::makeReplica();
    Queue::fake();
    $connectors = Connector::query()->orderBy('id')->get();
    $exception = new RuntimeException('Connect unavailable');
    $manager = Mockery::mock(ConnectorManager::class)->makePartial();
    $manager
        ->shouldReceive('checkStatus')
        ->once()
        ->with(Mockery::on(fn ($connector) => $connector->is($connectors[0])))
        ->andThrow($exception);
    $manager
        ->shouldReceive('checkStatus')
        ->once()
        ->with(Mockery::on(fn ($connector) => $connector->is($connectors[1])))
        ->andReturnUsing(fn (Connector $connector) => $connector->recordStatus(ConnectorStatus::Running));
    app()->instance(ConnectorManager::class, $manager);

    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description === 'ferry:refresh-statuses') {
            $event->run(app());
        }
    }

    Exceptions::assertReported(fn (RuntimeException $reported) => $reported === $exception);
    expect($connectors[0]->fresh()->checked_at)
        ->toBeNull()
        ->and($connectors[1]->fresh()->status)
        ->toBe(ConnectorStatus::Running);
    Queue::assertNothingPushed();
});
