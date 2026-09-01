<?php

namespace Tests\Feature\Api;

use App\Models\Calendar;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CalendarApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();
    }

    protected function asUser(?User $user = null): static
    {
        $user ??= $this->user;

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    public function test_it_lists_only_calendars_the_caller_can_see(): void
    {
        $stranger = User::factory()->create();
        $strangerTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        // Private calendar owned by someone else in the same workspace.
        $hidden = Calendar::factory()->create([
            'tenant_id' => $this->tenant->id,
            'owner_id' => User::factory()->create()->id,
        ]);

        $visible = Calendar::factory()->shared()->create([
            'tenant_id' => $this->tenant->id,
            'owner_id' => User::factory()->create()->id,
        ]);

        $ids = $this->asUser()->getJson('/api/v1/calendars')->assertOk()->json('data.*.id');

        $this->assertContains($visible->id, $ids, 'workspace-visible calendars should be listed');
        $this->assertNotContains($hidden->id, $ids, "another user's private calendar must not leak");
        $this->assertSame(
            [],
            array_intersect($ids, $strangerTenant->calendars()->pluck('id')->all()),
            'calendars from another workspace must not leak',
        );
    }

    public function test_it_creates_a_calendar_in_the_active_workspace(): void
    {
        $this->asUser()->postJson('/api/v1/calendars', [
            'name' => 'Releases',
            'color' => '#db2777',
            'visibility' => 'tenant',
        ])->assertCreated()->assertJsonPath('data.name', 'Releases');

        $this->assertDatabaseHas('calendars', [
            'name' => 'Releases',
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
        ]);
    }

    public function test_a_viewer_cannot_update_a_calendar_shared_with_them(): void
    {
        $viewer = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $viewer);

        $calendar = $this->user->calendars()->first();
        $calendar->shares()->create(['user_id' => $viewer->id, 'permission' => 'view']);

        $this->asUser($viewer)
            ->patchJson("/api/v1/calendars/{$calendar->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_sharing_requires_the_recipient_to_be_a_workspace_member(): void
    {
        $outsider = User::factory()->create();
        $calendar = $this->user->calendars()->first();

        $this->asUser()->postJson("/api/v1/calendars/{$calendar->id}/shares", [
            'email' => $outsider->email,
            'permission' => 'edit',
        ])->assertStatus(422);

        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member);

        $this->asUser()->postJson("/api/v1/calendars/{$calendar->id}/shares", [
            'email' => $member->email,
            'permission' => 'edit',
        ])->assertCreated();

        $this->assertDatabaseHas('calendar_shares', [
            'calendar_id' => $calendar->id,
            'user_id' => $member->id,
            'permission' => 'edit',
        ]);
    }

    public function test_the_tenant_header_switches_workspaces(): void
    {
        $second = app(WorkspaceService::class)->create($this->user, 'Second Co');

        $ids = $this->asUser()
            ->withHeader('X-Tenant', $second->slug)
            ->getJson('/api/v1/calendars')
            ->assertOk()
            ->json('data.*.tenant_id');

        $this->assertNotEmpty($ids);
        $this->assertSame([$second->id], array_values(array_unique($ids)));
    }

    public function test_the_tenant_header_rejects_workspaces_the_user_does_not_belong_to(): void
    {
        $stranger = User::factory()->create();
        $foreign = app(WorkspaceService::class)->create($stranger, 'Foreign Co');

        $this->asUser()
            ->withHeader('X-Tenant', $foreign->slug)
            ->getJson('/api/v1/calendars')
            ->assertForbidden();
    }
}
