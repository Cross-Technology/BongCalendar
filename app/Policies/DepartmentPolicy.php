<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Departments describe how a workspace is organised, so any member can see
 * them but only owners and admins may reshape them.
 */
class DepartmentPolicy
{
    public function view(User $user, Department $department): bool
    {
        return $user->belongsToTenant($department->tenant_id);
    }

    public function create(User $user, int $tenantId): bool
    {
        return $user->isTenantAdmin($tenantId);
    }

    public function update(User $user, Department $department): bool
    {
        return $user->isTenantAdmin($department->tenant_id);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->isTenantAdmin($department->tenant_id);
    }

    /** Who works in a department is part of its shape, so admins decide it. */
    public function manageMembers(User $user, Department $department): bool
    {
        return $user->isTenantAdmin($department->tenant_id);
    }
}
