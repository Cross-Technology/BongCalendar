<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceInvitePageTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme');
        $this->owner->refresh();
    }

    protected function invitee(): User
    {
        $user = User::factory()->create(['email' => 'guest@example.com']);
        app(WorkspaceService::class)->create($user, 'Guest Space');

        return $user->refresh();
    }

    public function test_inviting_creates_a_pending_invitation_and_no_membership(): void
    {
        $invitee = $this->invitee();

        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            ->set('member_email', 'guest@example.com')
            ->set('member_role', 'member')
            ->call('inviteMember')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('workspace_invitations', [
            'tenant_id' => $this->tenant->id,
            'email' => 'guest@example.com',
            'status' => 'pending',
        ]);

        $this->assertFalse($invitee->belongsToTenant($this->tenant->id), 'nobody joins before accepting');
    }

    public function test_the_invitee_sees_it_and_joins_by_accepting(): void
    {
        $invitee = $this->invitee();

        $invitation = WorkspaceInvitation::create([
            'tenant_id' => $this->tenant->id,
            'invited_by' => $this->owner->id,
            'email' => 'guest@example.com',
            'expires_at' => now()->addDays(14),
        ]);

        $this->actingAs($invitee)
            ->get('/invitations')
            ->assertOk()
            ->assertSee('Workspace invitations')
            ->assertSee('Acme');

        Livewire::actingAs($invitee)
            ->test('pages::invitations')
            ->call('acceptWorkspace', $invitation->id);

        $this->assertTrue($invitee->fresh()->belongsToTenant($this->tenant->id));
        $this->assertSame('accepted', $invitation->fresh()->status);
    }

    public function test_declining_keeps_the_invitee_out(): void
    {
        $invitee = $this->invitee();

        $invitation = WorkspaceInvitation::create([
            'tenant_id' => $this->tenant->id,
            'invited_by' => $this->owner->id,
            'email' => 'guest@example.com',
        ]);

        Livewire::actingAs($invitee)
            ->test('pages::invitations')
            ->call('declineWorkspace', $invitation->id);

        $this->assertFalse($invitee->fresh()->belongsToTenant($this->tenant->id));
        $this->assertSame('declined', $invitation->fresh()->status);
    }

    public function test_someone_elses_invitation_cannot_be_accepted(): void
    {
        $invitee = $this->invitee();
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        app(WorkspaceService::class)->create($stranger, 'Stranger Space');

        $invitation = WorkspaceInvitation::create([
            'tenant_id' => $this->tenant->id,
            'invited_by' => $this->owner->id,
            'email' => $invitee->email,
        ]);

        Livewire::actingAs($stranger->fresh())
            ->test('pages::invitations')
            ->call('acceptWorkspace', $invitation->id)
            ->assertForbidden();
    }

    public function test_a_plain_member_cannot_invite(): void
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');
        $member->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        Livewire::actingAs($member->fresh())
            ->test('pages::workspaces')
            ->call('manage', $this->tenant->id)
            ->set('member_email', 'guest@example.com')
            ->call('inviteMember')
            ->assertForbidden();
    }

    public function test_a_join_code_lets_someone_in_and_a_wrong_one_does_not(): void
    {
        $joiner = $this->invitee();
        $code = $this->tenant->fresh()->invite_code;

        Livewire::actingAs($joiner)
            ->test('pages::workspaces')
            ->set('join_code', 'WRONGONE')
            ->call('joinByCode')
            ->assertHasErrors('join_code');

        $this->assertFalse($joiner->fresh()->belongsToTenant($this->tenant->id));

        Livewire::actingAs($joiner)
            ->test('pages::workspaces')
            ->set('join_code', strtolower($code))
            ->call('joinByCode')
            ->assertHasNoErrors()
            ->assertSet('join_code', '');

        $this->assertTrue($joiner->fresh()->belongsToTenant($this->tenant->id));
    }

    public function test_regenerating_the_code_stops_the_old_one_working(): void
    {
        $joiner = $this->invitee();
        $old = $this->tenant->fresh()->invite_code;

        Livewire::actingAs($this->owner)
            ->test('pages::workspaces')
            ->call('regenerateCode', $this->tenant->id);

        $this->assertNotSame($old, $this->tenant->fresh()->invite_code);

        Livewire::actingAs($joiner)
            ->test('pages::workspaces')
            ->set('join_code', $old)
            ->call('joinByCode')
            ->assertHasErrors('join_code');
    }

    public function test_the_sidebar_badge_counts_workspace_invitations(): void
    {
        $invitee = $this->invitee();

        WorkspaceInvitation::create([
            'tenant_id' => $this->tenant->id,
            'invited_by' => $this->owner->id,
            'email' => $invitee->email,
        ]);

        $this->actingAs($invitee)
            ->get('/dashboard')
            ->assertOk()
            // The badge sits on the Invitations nav item.
            ->assertSee('Invitations');

        $this->assertSame(
            1,
            WorkspaceInvitation::pending()->forEmail($invitee->email)->count()
        );
    }
}
