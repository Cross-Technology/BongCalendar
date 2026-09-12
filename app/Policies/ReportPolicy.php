<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

/**
 * A day's report belongs to the department rather than to whoever typed it,
 * so any member of the workspace can read one and keep it up to date.
 * Removing the record of a day is heavier than correcting it, so deletion
 * stays with the author or an admin.
 */
class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        return $user->belongsToTenant($report->tenant_id);
    }

    public function create(User $user, int $tenantId): bool
    {
        return $user->belongsToTenant($tenantId);
    }

    public function update(User $user, Report $report): bool
    {
        return $user->belongsToTenant($report->tenant_id);
    }

    public function delete(User $user, Report $report): bool
    {
        return $report->author_id === $user->id || $user->isTenantAdmin($report->tenant_id);
    }
}
