<?php

use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Cerber\Models\User;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Policies\ReplicaPolicy;
use PHPinnacle\Ferry\Policies\UpstreamPolicy;
use PHPinnacle\Ferry\Resources\Actions\RefreshConnectorStatusesAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\PauseReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\StartReplicaAction;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\PrepareStructureAction;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Gate::policy(Replica::class, ReplicaPolicy::class);
    Gate::policy(Upstream::class, UpstreamPolicy::class);

    $user = new class extends User {
        /** @var array<string, bool> */
        public array $testPermissions = [];

        public function able(string $ability, $tenant = null): bool
        {
            return (bool) ($this->testPermissions[$ability] ?? false);
        }

        public function can($abilities, $arguments = []): bool
        {
            return (bool) ($this->testPermissions[$abilities] ?? false);
        }
    };

    $this->actingAs($user);
    $this->user = $user;
});

it('authorizes table actions against update permission', function (string $actionClass) {
    /** @var Action $action */
    $action = $actionClass::table();
    $replica = new Replica;

    $action->record($replica);

    $this->user->testPermissions['update_replica'] = false;

    expect($action->isAuthorized())->toBeFalse();

    $this->user->testPermissions['update_replica'] = true;

    expect($action->isAuthorized())->toBeTrue();
})->with([
    'start' => StartReplicaAction::class,
    'pause' => PauseReplicaAction::class,
]);

it('authorizes structure preparation against update permission', function (string $context) {
    $action = PrepareStructureAction::$context()->record(new Upstream);

    $this->user->testPermissions['update_upstream'] = false;

    expect($action->isAuthorized())->toBeFalse();

    $this->user->testPermissions['update_upstream'] = true;

    expect($action->isAuthorized())->toBeTrue();
})->with(['table', 'form']);

it('requires access to both resource lists to refresh all connector statuses', function (
    bool $replicas,
    bool $upstreams,
    bool $allowed,
) {
    $this->user->testPermissions['view_any_replica'] = $replicas;
    $this->user->testPermissions['view_any_upstream'] = $upstreams;

    expect(RefreshConnectorStatusesAction::table()->isAuthorized())->toBe($allowed);
})->with([
    'both lists' => [true, true, true],
    'replicas only' => [true, false, false],
    'upstreams only' => [false, true, false],
    'neither list' => [false, false, false],
]);
