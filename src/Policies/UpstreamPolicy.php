<?php

namespace PHPinnacle\Ferry\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Access\Authorizable;
use PHPinnacle\Ferry\Models\Upstream;

class UpstreamPolicy
{
    use HandlesAuthorization;

    public function create(Authorizable $user): bool
    {
        return $user->can('create_upstream');
    }

    public function delete(Authorizable $user, Upstream $record): bool
    {
        return $user->can('delete_upstream') && !$record->isInUse();
    }

    public function deleteAny(Authorizable $user): bool
    {
        return $user->can('delete_any_upstream');
    }

    public function update(Authorizable $user, Upstream $record): bool
    {
        return $user->can('update_upstream');
    }

    public function view(Authorizable $user, Upstream $record): bool
    {
        return $user->can('view_upstream');
    }

    public function viewAny(Authorizable $user): bool
    {
        return $user->can('view_any_upstream');
    }
}
