<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPinnacle\Cerber\Models\User;
use PHPinnacle\Ferry\Enums\ConnectorStatus;
use PHPinnacle\Ferry\Enums\ReplicaStatus;
use PHPinnacle\Ferry\Resources\Actions\RequestSnapshotAction;
use PHPinnacle\Ferry\Services\ConnectorManager;
use PHPinnacle\Ferry\Services\OriginFactory;
use PHPinnacle\Ferry\Tests\TestCase;

require_once __DIR__ . '/../TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Queue::fake();
    $this->actingAs(new class extends User {
        public function able(string $ability, $tenant = null): bool
        {
            return $this->name !== 'denied' && in_array($ability, ['update_upstream', 'update_replica'], true);
        }
    });
});

it('publishes a manual reread request and reports publication or failure', function (bool $replicaOwner, bool $fails) {
    $replica = TestCase::makeReplica([], ['status' => ReplicaStatus::Active]);
    foreach ([$replica->upstream->connector, $replica->connector] as $connector) {
        $connector->markApplied();
        $connector->recordStatus(ConnectorStatus::Running);
    }

    $owner = $replicaOwner ? $replica : $replica->upstream;
    $owner->refresh();
    $origin = app(OriginFactory::class)->for($owner);
    if ($fails) {
        $origin = Mockery::mock($origin)->makePartial();
        $origin->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('Kafka unavailable'));
        $origins = Mockery::mock(OriginFactory::class);
        $origins->shouldReceive('for')->with($owner)->andReturn($origin);
        app()->instance(OriginFactory::class, $origins);
    }

    Queue::fake();
    $action = RequestSnapshotAction::make()->record($owner);

    expect($action->isVisible())->toBeTrue()->and($action->isConfirmationRequired())->toBeTrue();
    $action->call();

    Notification::assertNotified(
        Notification::make()
            ->title(
                $fails ? 'Kafka unavailable' : __('phpinnacle-ferry::resources.connector.messages.snapshot_requested'),
            )
            ->status($fails ? 'danger' : 'success'),
    );
    if (!$fails) {
        expect($origin->operations)->toBe([['snapshot', $owner->code]]);
    }

    Queue::assertNothingPushed();
})->with(['replica' => true, 'upstream' => false])->with(['sent' => false, 'failed' => true]);

it('requires ready connectors for manual rereads', function (bool $replicaOwner, string $unavailable) {
    $replica = TestCase::makeReplica([], ['status' => ReplicaStatus::Active]);
    $upstream = $replica->upstream;
    foreach ([$upstream->connector, $replica->connector] as $connector) {
        $connector->markApplied();
        $connector->recordStatus(ConnectorStatus::Running);
    }

    match ($unavailable) {
        'source paused' => $upstream->connector->recordStatus(ConnectorStatus::Paused),
        'source unapplied' => $upstream->connector->forceFill(['applied_config' => null])->saveQuietly(),
        'sink failed' => $replica->connector->recordStatus(ConnectorStatus::Failed),
        'replica paused' => $replica->pause(),
    };
    $owner = $replicaOwner ? $replica->refresh() : $upstream->refresh();
    $available = !$replicaOwner && in_array($unavailable, ['sink failed', 'replica paused'], true);

    expect(RequestSnapshotAction::make()->record($owner)->isVisible())->toBe($available);
    if (!$available) {
        expect(fn () => app(ConnectorManager::class)->snapshot($owner))->toThrow(ValidationException::class);
    }
})->with(['replica' => true, 'upstream' => false])
    ->with(['source paused', 'source unapplied', 'sink failed', 'replica paused']);

it('authorizes rereads through the owner update policy', function (bool $replicaOwner) {
    $replica = TestCase::makeReplica();
    auth()->user()->name = 'denied';
    $owner = $replicaOwner ? $replica : $replica->upstream;

    expect(RequestSnapshotAction::make()->record($owner)->isAuthorized())->toBeFalse();
})->with(['replica' => true, 'upstream' => false]);
