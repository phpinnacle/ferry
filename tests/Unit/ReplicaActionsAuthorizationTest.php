<?php

use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Cerber\Models\User;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Policies\ReplicaPolicy;
use PHPinnacle\Ferry\Resources\Replicas\Actions\ActivateReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\PauseReplicaAction;
use PHPinnacle\Ferry\Resources\Replicas\Actions\RestartReplicaAction;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Gate::policy(Replica::class, ReplicaPolicy::class);

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
    'activate' => ActivateReplicaAction::class,
    'pause' => PauseReplicaAction::class,
    'restart' => RestartReplicaAction::class,
]);
