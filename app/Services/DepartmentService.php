<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use InvalidArgumentException;

class DepartmentService
{
    /**
     * `lead` carries no extra permission — departments scope work rather than
     * gate it — but it decides which department a task lands in when someone
     * belongs to several.
     */
    public const ROLES = ['lead', 'member'];

    /**
     * Put someone in a department, or change the role they hold in it.
     *
     * @throws InvalidArgumentException
     */
    public function addMember(Department $department, User $user, string $role = 'member'): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unknown department role [{$role}].");
        }

        // A department belongs to one workspace, so its people must too —
        // otherwise a member of another workspace ends up inside it.
        if (! $user->belongsToTenant($department->tenant_id)) {
            throw new InvalidArgumentException('That user is not a member of this workspace.');
        }

        $department->members()->syncWithoutDetaching([
            $user->id => ['role' => $role, 'joined_at' => now()],
        ]);
    }

    public function removeMember(Department $department, User $user): void
    {
        $department->members()->detach($user->id);
    }
}
