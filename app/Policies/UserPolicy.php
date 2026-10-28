<?php

namespace App\Policies;

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::USERS_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::USERS_MANAGE);
    }

    public function update(User $actor, User $target): bool
    {
        if (! $actor->can(Permission::USERS_MANAGE) || ! $this->sameOrganization($actor, $target)) {
            return false;
        }

        // Only owners may change other owners.
        return ! $target->hasRole(Role::Owner->value) || $actor->hasRole(Role::Owner->value);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->isNot($target) && $this->update($actor, $target);
    }

    private function sameOrganization(User $actor, User $target): bool
    {
        return $actor->organization_id !== null && $actor->organization_id === $target->organization_id;
    }
}
