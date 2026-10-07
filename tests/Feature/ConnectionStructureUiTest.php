<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\PrepareStructureJob;
use PHPinnacle\Ferry\Resources\Connections\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('hides the preparation action while a live preparation is running', function () {
    $connection = TestCase::makeConnection();

    expect(PrepareStructureAction::table()->record($connection)->isVisible())->toBeFalse();
});

it('offers the preparation action again when the running attempt went stale', function () {
    $connection = TestCase::makeConnection([], [
        'heartbeat_at' => CarbonImmutable::now()->subSeconds(
            config('phpinnacle-ferry.structure.stale_after') + 1,
        ),
    ]);

    expect(PrepareStructureAction::table()->record($connection)->isVisible())->toBeTrue();
});

it('asks for confirmation only before re-reading a published snapshot', function (
    StructureStatus $status,
    bool $confirms,
) {
    $connection = TestCase::makeConnection([], ['status' => $status]);

    expect(PrepareStructureAction::table()->record($connection)->isConfirmationRequired())->toBe($confirms);
})->with([
    'not prepared yet' => [StructureStatus::Pending, false],
    'snapshot published' => [StructureStatus::Ready, true],
    'last attempt failed' => [StructureStatus::Failed, false],
]);

it('queues the preparation and notifies the operator', function () {
    $connection = TestCase::makeConnection([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    PrepareStructureAction::table()->record($connection)->call();

    expect($connection->fresh()->status)
        ->toBe(StructureStatus::Preparing)
        ->and($connection->status)
        ->toBe(StructureStatus::Preparing)
        ->and($connection->run_id)
        ->not
        ->toBeNull()
        ->and(session()->get('filament.notifications'))
        ->toHaveCount(1)
        ->and(session()->get('filament.notifications.0.title'))
        ->toBe(__('phpinnacle-ferry::resources.connection.messages.structure_queued'));

    Queue::assertPushed(PrepareStructureJob::class, 2);
});

it('warns instead of queueing a second preparation', function () {
    $connection = TestCase::makeConnection();

    PrepareStructureAction::table()->record($connection)->call();

    expect(session()->get('filament.notifications.0.title'))
        ->toBe(__('phpinnacle-ferry::resources.connection.messages.structure_active'));

    Queue::assertPushed(PrepareStructureJob::class, 1);
});
