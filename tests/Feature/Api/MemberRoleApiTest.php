<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class MemberRoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme');
        $this->owner->refresh();
    }

    /**
     * Swap the bearer token. The guard resolves its user once per test run, so
     * this must be called before the test's first request.
     */
    protected function actingAsApi(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    protected function member(string $role = 'member'): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $user, $role);

        return $user->refresh();
    }

    protected function roleOf(User $user): ?string
    {
        return $user->fresh()->roleIn($this->tenant->id);
    }

    public function test_the_owner_can_promote_a_member_to_admin(): void
    {
        $member = $this->member();
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$member->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->assertSame('admin', $this->roleOf($member));
    }

    public function test_the_owner_can_demote_an_admin_to_member(): void
    {
        $admin = $this->member('admin');
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$admin->id}", ['role' => 'member'])
            ->assertOk()
            ->assertJsonPath('data.role', 'member');

        $this->assertSame('member', $this->roleOf($admin));
    }

    /**
     * An admin who could appoint admins could promote themselves past the
     * owner, so the ability stops at the owner even though admins may
     * otherwise add and remove members.
     */
    public function test_an_admin_cannot_change_roles(): void
    {
        $admin = $this->member('admin');
        $other = $this->member();

        $this->actingAsApi($admin);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$other->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('member', $this->roleOf($other));
    }

    public function test_a_member_cannot_change_roles(): void
    {
        $member = $this->member();
        $other = $this->member();

        $this->actingAsApi($member);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$other->id}", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_the_owners_own_role_cannot_be_changed(): void
    {
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$this->owner->id}", ['role' => 'member'])
            ->assertStatus(422);

        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_ownership_cannot_be_handed_over_through_this_endpoint(): void
    {
        $member = $this->member();
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$member->id}", ['role' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame('member', $this->roleOf($member));
    }

    public function test_the_role_is_required_and_must_be_known(): void
    {
        $member = $this->member();
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$member->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$member->id}", ['role' => 'superuser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_someone_outside_the_workspace_cannot_be_given_a_role(): void
    {
        $outsider = User::factory()->create();
        $this->actingAsApi($this->owner);

        $this->patchJson("/api/v1/workspaces/{$this->tenant->slug}/members/{$outsider->id}", ['role' => 'admin'])
            ->assertNotFound();

        $this->assertNull($this->roleOf($outsider));
    }

    /** The role has to actually buy something — departments are admin-only. */
    public function test_a_promoted_member_gains_admin_powers(): void
    {
        $member = $this->member();

        $this->actingAsApi($member);

        $this->postJson('/api/v1/departments', ['name' => 'Ops'])->assertForbidden();

        app(WorkspaceService::class)->changeRole($this->tenant, $member, 'admin');

        $this->postJson('/api/v1/departments', ['name' => 'Ops'])->assertCreated();
    }

    public function test_a_demoted_admin_loses_them_again(): void
    {
        $admin = $this->member('admin');

        $this->actingAsApi($admin);

        $this->postJson('/api/v1/departments', ['name' => 'Ops'])->assertCreated();

        app(WorkspaceService::class)->changeRole($this->tenant, $admin, 'member');

        $this->postJson('/api/v1/departments', ['name' => 'Sales'])->assertForbidden();
        $this->assertSame(1, Department::where('tenant_id', $this->tenant->id)->count());
    }
}
