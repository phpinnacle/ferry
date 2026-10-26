<?php

namespace PHPinnacle\Ferry\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use PHPinnacle\Ferry\Models\Replica;

class ReplicaPolicy
{
    use HandlesAuthorization;

    public function create(Authorizable $user): bool
    {
        return $user->can('create_replica');
    }

    public function delete(Authorizable $user, Replica $record): bool
    {
        return $user->can('delete_replica');
    }

    public function deleteAny(Authorizable $user): bool
    {
        return $user->can('delete_any_replica');
    }

    public function update(Authorizable $user, Replica $record): bool
    {
        return $user->can('update_replica');
    }

    public function view(Authorizable $user, Replica $record): bool
    {
        return $user->can('view_replica');
    }

    public function viewAny(Authorizable $user): bool
    {
        return $user->can('view_any_replica');
    }
}
