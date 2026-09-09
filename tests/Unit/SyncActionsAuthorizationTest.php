<?php

use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use PHPinnacle\Cerber\Models\User;
use PHPinnacle\Ferry\Models\Sync;
use PHPinnacle\Ferry\Policies\SyncPolicy;
use PHPinnacle\Ferry\Resources\Syncs\Actions\ActivateSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\Actions\PauseSyncAction;
use PHPinnacle\Ferry\Resources\Syncs\Actions\RestartSyncAction;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Gate::policy(Sync::class, SyncPolicy::class);

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
    $sync = new Sync;

    $action->record($sync);

    $this->user->testPermissions['update_sync'] = false;

    expect($action->isAuthorized())->toBeFalse();

    $this->user->testPermissions['update_sync'] = true;

    expect($action->isAuthorized())->toBeTrue();
})->with([
    'activate' => ActivateSyncAction::class,
    'pause' => PauseSyncAction::class,
    'restart' => RestartSyncAction::class,
]);
