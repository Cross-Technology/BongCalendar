<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
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

    /**
     * Switches identity mid-test. One process serves every request here, so the
     * guard and the JWT instance both cache the last caller and have to be
     * cleared or the second user is silently ignored.
     */
    protected function as(User $user): static
    {
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user->fresh()));
    }

    public function test_an_invitation_grants_nothing_until_it_is_accepted(): void
    {
        $invitee = User::factory()->create(['email' => 'guest@example.com']);

        $this->as($this->owner)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", ['email' => 'guest@example.com'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role', 'member');

        // Still an outsider while the invitation sits unanswered.
        $this->assertFalse($invitee->fresh()->belongsToTenant($this->tenant->id));

        $invitation = WorkspaceInvitation::firstOrFail();

        $this->as($invitee)
            ->getJson('/api/v1/workspace-invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workspace_name', 'Acme');

        $this->as($invitee)
            ->postJson("/api/v1/workspace-invitations/{$invitation->id}/accept")
            ->assertOk()
            ->assertJsonPath('message', 'You joined Acme.');

        $this->assertTrue($invitee->fresh()->belongsToTenant($this->tenant->id));
        $this->assertSame('accepted', $invitation->fresh()->status);
    }

    public function test_declining_leaves_the_invitee_out(): void
    {
        $invitee = User::factory()->create(['email' => 'guest@example.com']);

        $this->as($this->owner)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", ['email' => 'guest@example.com'])
            ->assertCreated();

        $invitation = WorkspaceInvitation::firstOrFail();

        $this->as($invitee)
            ->postJson("/api/v1/workspace-invitations/{$invitation->id}/decline")
            ->assertOk();

        $this->assertSame('declined', $invitation->fresh()->status);
        $this->assertFalse($invitee->fresh()->belongsToTenant($this->tenant->id));

        // A declined invitation cannot then be accepted.
        $this->as($invitee)
            ->postJson("/api/v1/workspace-invitations/{$invitation->id}/accept")
            ->assertStatus(410);
    }

    public function test_an_invitation_addressed_to_someone_else_is_refused(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->as($this->owner)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", ['email' => 'guest@example.com'])
            ->assertCreated();

        $invitation = WorkspaceInvitation::firstOrFail();

        $this->as($stranger)
            ->postJson("/api/v1/workspace-invitations/{$invitation->id}/accept")
            ->assertForbidden();

        $this->assertFalse($stranger->fresh()->belongsToTenant($this->tenant->id));
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $invitee = User::factory()->create(['email' => 'guest@example.com']);

        $invitation = WorkspaceInvitation::create([
            'tenant_id' => $this->tenant->id,
            'invited_by' => $this->owner->id,
            'email' => 'guest@example.com',
            'expires_at' => now()->subDay(),
        ]);

        $this->as($invitee)
            ->postJson("/api/v1/workspace-invitations/{$invitation->id}/accept")
            ->assertStatus(410)
            ->assertJsonPath('message', 'This invitation has expired. Ask for a new one.');

        // It also stays out of the notification list.
        $this->as($invitee)->getJson('/api/v1/workspace-invitations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_re_inviting_refreshes_the_open_invitation_rather_than_stacking(): void
    {
        foreach (['member', 'admin'] as $role) {
            $this->as($this->owner)
                ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", [
                    'email' => 'guest@example.com',
                    'role' => $role,
                ])->assertCreated();
        }

        $this->assertSame(1, WorkspaceInvitation::count());
        $this->assertSame('admin', WorkspaceInvitation::firstOrFail()->role);
    }

    public function test_only_admins_may_invite_or_revoke(): void
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');

        $this->as($member)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", ['email' => 'guest@example.com'])
            ->assertForbidden();
    }

    public function test_inviting_an_existing_member_is_rejected(): void
    {
        $member = User::factory()->create(['email' => 'inside@example.com']);
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');

        $this->as($this->owner)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invitations", ['email' => 'inside@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'They are already a member of this workspace.');
    }

    /* ------------------------------------------------------------ join code */

    public function test_a_workspace_code_can_be_previewed_and_redeemed(): void
    {
        $joiner = User::factory()->create();
        $code = $this->tenant->fresh()->invite_code;

        $this->assertNotNull($code, 'every workspace gets a code');

        $this->as($joiner)
            ->getJson("/api/v1/workspace-codes/{$code}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme');

        $this->as($joiner)
            ->postJson('/api/v1/workspace-codes/join', ['code' => strtolower($code)])
            ->assertOk()
            ->assertJsonPath('message', 'You joined Acme.');

        $this->assertTrue($joiner->fresh()->belongsToTenant($this->tenant->id));
    }

    public function test_a_wrong_code_says_so_and_joins_nothing(): void
    {
        $joiner = User::factory()->create();

        $this->as($joiner)
            ->postJson('/api/v1/workspace-codes/join', ['code' => 'NOPENOPE'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That code does not match any workspace.');

        $this->assertSame(0, $joiner->fresh()->tenants()->count());
    }

    public function test_regenerating_the_code_invalidates_the_old_one(): void
    {
        $joiner = User::factory()->create();
        $old = $this->tenant->fresh()->invite_code;

        $new = $this->as($this->owner)
            ->postJson("/api/v1/workspaces/{$this->tenant->id}/invite-code")
            ->assertOk()
            ->json('data.invite_code');

        $this->assertNotSame($old, $new);

        $this->as($joiner)
            ->postJson('/api/v1/workspace-codes/join', ['code' => $old])
            ->assertNotFound();
    }
}
