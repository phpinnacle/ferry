<?php

namespace PHPinnacle\Ferry\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use PHPinnacle\Ferry\Models\Sync;

class SyncPolicy
{
    use HandlesAuthorization;

    public function create(Authorizable $user): bool
    {
        return $user->can('create_sync');
    }

    public function delete(Authorizable $user, Sync $record): bool
    {
        return $user->can('delete_sync');
    }

    public function deleteAny(Authorizable $user): bool
    {
        return $user->can('delete_any_sync');
    }

    public function update(Authorizable $user, Sync $record): bool
    {
        return $user->can('update_sync');
    }

    public function view(Authorizable $user, Sync $record): bool
    {
        return $user->can('view_sync');
    }

    public function viewAny(Authorizable $user): bool
    {
        return $user->can('view_any_sync');
    }
}
