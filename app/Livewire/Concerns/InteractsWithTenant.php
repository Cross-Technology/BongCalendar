<?php

namespace App\Livewire\Concerns;

use App\Models\Tenant;
use App\Models\User;

/**
 * Livewire's update endpoint does not re-run a page route's custom middleware,
 * so components resolve the active workspace themselves on every request
 * instead of trusting the ResolveTenant middleware from the initial load.
 */
trait InteractsWithTenant
{
    public function currentUser(): User
    {
        return auth()->user();
    }

    public function currentTenant(): ?Tenant
    {
        return $this->currentUser()->currentTenant;
    }

    public function tenantId(): ?int
    {
        return $this->currentUser()->current_tenant_id;
    }

    /** Bail out to the workspace picker when the user has no active workspace. */
    protected function requireTenant(): Tenant
    {
        $tenant = $this->currentTenant();

        if (! $tenant) {
            $this->redirectRoute('workspaces.index', navigate: true);
            abort(409, 'No workspace selected.');
        }

        return $tenant;
    }

    protected function userTimezone(): string
    {
        return $this->currentUser()->timezone ?: 'UTC';
    }
}
