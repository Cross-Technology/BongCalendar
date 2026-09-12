<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberRolePageTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Rady']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme');
        $this->owner->refresh();
    }

    protected function member(string $role = 'member', string $name = 'Sophea'): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(WorkspaceService::class)->addMember($this->tenant, $user, $role);

        return $user->refresh();
    }

    protected function roleOf(User $user): ?string
    {
        return $user->fresh()->roleIn($this->tenant->id);
    }

    public function test_the_owner_gets_a_role_control_for_each_member(): void
    {
        $this->member();

        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            ->assertSee('changeRole', escape: false)
            // The owner's own row stays a plain badge.
            ->assertSee('Owner');
    }

    public function test_the_owner_can_promote_and_demote(): void
    {
        $member = $this->member();

        $component = Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            ->call('changeRole', $this->tenant->id, $member->id, 'admin');

        $this->assertSame('admin', $this->roleOf($member));

        $component->call('changeRole', $this->tenant->id, $member->id, 'member');

        $this->assertSame('member', $this->roleOf($member));
    }

    public function test_an_admin_sees_roles_but_cannot_change_them(): void
    {
        $admin = $this->member('admin');
        $other = $this->member('member', 'Dara');

        Livewire::actingAs($admin)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            // They still manage members, just not what those members may do.
            ->assertSee('Dara')
            ->assertDontSee('changeRole', escape: false);

        Livewire::actingAs($admin)
            ->test('pages::workspaces')
            ->call('changeRole', $this->tenant->id, $other->id, 'admin')
            ->assertForbidden();

        $this->assertSame('member', $this->roleOf($other));
    }

    public function test_a_plain_member_cannot_change_roles(): void
    {
        $member = $this->member();
        $other = $this->member('member', 'Dara');

        Livewire::actingAs($member)
            ->test('pages::workspaces')
            ->call('changeRole', $this->tenant->id, $other->id, 'admin')
            ->assertForbidden();
    }

    public function test_the_owner_cannot_demote_themselves(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            // The owner's row renders a plain badge, so this is a crafted
            // request rather than something the page offers — it has to be
            // refused without taking the workspace's only owner away.
            ->call('changeRole', $this->tenant->id, $this->owner->id, 'member')
            ->assertOk();

        $this->assertSame('owner', $this->roleOf($this->owner));
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $member = $this->member();

        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            ->call('changeRole', $this->tenant->id, $member->id, 'owner');

        $this->assertSame('member', $this->roleOf($member));
    }

    public function test_roles_cannot_be_changed_across_workspaces(): void
    {
        // The owner here is an outsider over there.
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');
        $theirMember = User::factory()->create();
        app(WorkspaceService::class)->addMember($otherTenant, $theirMember, 'member');

        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('changeRole', $otherTenant->id, $theirMember->id, 'admin')
            ->assertForbidden();

        $this->assertSame('member', $theirMember->fresh()->roleIn($otherTenant->id));
    }
}
