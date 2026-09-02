<?php

namespace PHPinnacle\Ferry\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use PHPinnacle\Ferry\Models\Connection;

class ConnectionPolicy
{
    use HandlesAuthorization;

    public function create(Authorizable $user): bool
    {
        return $user->can('create_connection');
    }

    public function delete(Authorizable $user, Connection $record): bool
    {
        return $user->can('delete_connection') && !$record->isInUse();
    }

    public function deleteAny(Authorizable $user): bool
    {
        return $user->can('delete_any_connection');
    }

    public function update(Authorizable $user, Connection $record): bool
    {
        return $user->can('update_connection');
    }

    public function view(Authorizable $user, Connection $record): bool
    {
        return $user->can('view_connection');
    }

    public function viewAny(Authorizable $user): bool
    {
        return $user->can('view_any_connection');
    }
}
