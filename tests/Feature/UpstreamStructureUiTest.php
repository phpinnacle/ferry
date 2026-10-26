<?php

use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\PrepareStructureAction;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
});

it('hides the preparation action while a live preparation is running', function () {
    $upstream = TestCase::makeUpstream();

    expect(PrepareStructureAction::table()->record($upstream)->isVisible())->toBeFalse();
});

it('offers the preparation action again when the running attempt went stale', function () {
    $upstream = TestCase::makeUpstream([], [
        'updated_at' => CarbonImmutable::now()->subSeconds(
            config('phpinnacle-ferry.structure.stale_after') + 1,
        ),
    ]);

    expect(PrepareStructureAction::table()->record($upstream)->isVisible())->toBeTrue();
});

it('asks for confirmation only before re-reading a published snapshot', function (
    StructureStatus $status,
    bool $confirms,
) {
    $upstream = TestCase::makeUpstream([], ['status' => $status]);

    expect(PrepareStructureAction::table()->record($upstream)->isConfirmationRequired())->toBe($confirms);
})->with([
    'not prepared yet' => [StructureStatus::Pending, false],
    'snapshot published' => [StructureStatus::Ready, true],
    'last attempt failed' => [StructureStatus::Failed, false],
]);

it('queues the preparation and notifies the operator', function () {
    $upstream = TestCase::makeUpstream([], ['status' => StructureStatus::Ready, 'generation' => 1]);

    PrepareStructureAction::table()->record($upstream)->call();

    expect($upstream->fresh()->structure->status)
        ->toBe(StructureStatus::Preparing)
        ->and($upstream->structure->status)
        ->toBe(StructureStatus::Preparing)
        ->and($upstream->structure->run_id)
        ->not->toBeNull();

    Notification::assertNotified(
        Notification::make()
            ->title(__('phpinnacle-ferry::resources.upstream.messages.structure_queued'))
            ->success(),
    );

    Queue::assertPushed(PrepareStructureJob::class, 2);
});

it('warns instead of queueing a second preparation', function () {
    $upstream = TestCase::makeUpstream();

    PrepareStructureAction::table()->record($upstream)->call();

    Notification::assertNotified(
        Notification::make()
            ->title(__('phpinnacle-ferry::resources.upstream.messages.structure_active'))
            ->warning(),
    );

    Queue::assertPushed(PrepareStructureJob::class, 1);
});

it('hides the preparation action without a selected adapter', function () {
    $upstream = TestCase::makeUpstream(['adapter' => null]);

    expect(PrepareStructureAction::table()->record($upstream)->isVisible())->toBeFalse();
    Queue::assertNotPushed(PrepareStructureJob::class);
});
