<?php

namespace App\Policies;

use App\Models\TaskTemplate;
use App\Models\User;

/**
 * Templates describe how a workspace works, so every member can see and use
 * them. Changing one is left to whoever wrote it, or an admin.
 */
class TaskTemplatePolicy
{
    public function view(User $user, TaskTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }

    public function create(User $user, int $tenantId): bool
    {
        return $user->belongsToTenant($tenantId);
    }

    /** Using a template only bumps its counters, so any member may. */
    public function use(User $user, TaskTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id);
    }

    public function update(User $user, TaskTemplate $template): bool
    {
        return $template->created_by === $user->id
            || $user->isTenantAdmin($template->tenant_id);
    }

    public function delete(User $user, TaskTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
