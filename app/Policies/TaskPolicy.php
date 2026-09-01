<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Tasks are workspace-wide: any member sees them and can pick one up. Editing
 * is deliberately open to the whole workspace (the mobile app lets anyone move
 * a card), while deletion stays with the creator, the assignee, or an admin.
 */
class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->belongsToTenant($task->tenant_id);
    }

    public function create(User $user, int $tenantId): bool
    {
        return $user->belongsToTenant($tenantId);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->belongsToTenant($task->tenant_id);
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->created_by === $user->id
            || $task->assignee_id === $user->id
            || $user->isTenantAdmin($task->tenant_id);
    }
}
