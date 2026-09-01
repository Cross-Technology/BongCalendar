<?php

namespace App\Services;

use App\Models\Calendar;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    /**
     * Create a workspace, make the user its owner, and give them a default
     * calendar so they never land on an empty dashboard.
     */
    public function create(User $user, string $name, ?string $timezone = null): Tenant
    {
        return DB::transaction(function () use ($user, $name, $timezone) {
            $tenant = Tenant::create([
                'name' => $name,
                // Set explicitly rather than leaning on the model's creating hook,
                // which is muted under seeders using WithoutModelEvents.
                'slug' => Tenant::uniqueSlug($name),
                'timezone' => $timezone ?: $user->timezone ?: 'UTC',
                'owner_id' => $user->id,
            ]);

            $tenant->users()->attach($user->id, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            $this->createDefaultCalendar($tenant, $user);

            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            return $tenant->fresh();
        });
    }

    public function createDefaultCalendar(Tenant $tenant, User $user): Calendar
    {
        return Calendar::create([
            'tenant_id' => $tenant->id,
            'owner_id' => $user->id,
            'name' => 'My Calendar',
            'color' => '#2563eb',
            'timezone' => $tenant->timezone,
            'visibility' => 'private',
            'is_default' => true,
        ]);
    }

    /** Add a member, giving them their own default calendar in the workspace. */
    public function addMember(Tenant $tenant, User $user, string $role = 'member'): void
    {
        DB::transaction(function () use ($tenant, $user, $role) {
            if ($tenant->users()->whereKey($user->id)->exists()) {
                $tenant->users()->updateExistingPivot($user->id, ['role' => $role]);

                return;
            }

            $tenant->users()->attach($user->id, [
                'role' => $role,
                'joined_at' => now(),
            ]);

            $this->createDefaultCalendar($tenant, $user);

            $user->current_tenant_id ??= $tenant->id;
            $user->save();
        });
    }

    public function removeMember(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user) {
            $tenant->users()->detach($user->id);

            if ($user->current_tenant_id === $tenant->id) {
                $next = $user->tenants()->first();
                $user->forceFill(['current_tenant_id' => $next?->id])->save();
            }
        });
    }
}
