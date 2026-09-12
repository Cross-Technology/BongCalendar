<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant->id);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isTenantAdmin($tenant->id);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $tenant->owner_id === $user->id;
    }

    /** Inviting and removing members. */
    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->isTenantAdmin($tenant->id);
    }

    /**
     * Changing what a member is allowed to do. Held tighter than
     * manageMembers: an admin who could appoint admins could promote
     * themselves past the owner, so only the owner reshapes the roster.
     */
    public function manageRoles(User $user, Tenant $tenant): bool
    {
        return $tenant->owner_id === $user->id;
    }
}
