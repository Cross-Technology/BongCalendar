<?php

namespace Tests\Feature\Api;

use App\Models\TaskTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class TaskTemplateApiTest extends TestCase
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

        $this->as($this->user);
    }

    protected function as(User $user): static
    {
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user->fresh()));
    }

    public function test_a_template_is_saved_with_its_checklist(): void
    {
        $template = $this->postJson('/api/v1/task-templates', [
            'title' => 'Open the shop',
            'priority' => 'high',
            'checklist' => ['Unlock the door', 'Count the float', 'Switch the sign'],
            'tags' => ['daily'],
        ])->assertCreated()->json('data');

        $this->assertSame('Open the shop', $template['name'], 'the name falls back to the title');
        $this->assertCount(3, $template['checklist']);
        $this->assertSame(0, $template['use_count']);

        $this->getJson('/api/v1/task-templates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Open the shop');
    }

    public function test_it_survives_for_the_next_device_that_signs_in(): void
    {
        TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Closing routine',
        ]);

        // A second member of the workspace sees the same templates.
        $colleague = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $colleague, 'member');
        $colleague->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        $this->as($colleague)
            ->getJson('/api/v1/task-templates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Closing routine');
    }

    public function test_using_a_template_bumps_its_counters(): void
    {
        $template = TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->postJson("/api/v1/task-templates/{$template->id}/used")
            ->assertOk()
            ->assertJsonPath('data.use_count', 1);

        $this->assertNotNull($template->fresh()->last_used_at);
    }

    public function test_anyone_may_use_a_template_but_only_its_author_may_change_it(): void
    {
        $template = TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Owner routine',
        ]);

        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');
        $member->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        // Using it is fine...
        $this->as($member)
            ->postJson("/api/v1/task-templates/{$template->id}/used")
            ->assertOk();

        // ...editing and deleting are not.
        $this->as($member)
            ->patchJson("/api/v1/task-templates/{$template->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->as($member)
            ->deleteJson("/api/v1/task-templates/{$template->id}")
            ->assertForbidden();

        $this->assertSame('Owner routine', $template->fresh()->name);
    }

    public function test_an_admin_can_tidy_up_someone_elses_template(): void
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');

        $template = TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $member->id,
        ]);

        $this->as($this->user)
            ->deleteJson("/api/v1/task-templates/{$template->id}")
            ->assertOk();

        $this->assertModelMissing($template);
    }

    public function test_templates_from_another_workspace_are_invisible(): void
    {
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        TaskTemplate::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
        ]);

        $this->as($this->user)
            ->getJson('/api/v1/task-templates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_most_recently_used_comes_first(): void
    {
        $old = TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
            'name' => 'Rarely', 'last_used_at' => now()->subWeek(),
        ]);
        $fresh = TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
            'name' => 'Daily', 'last_used_at' => now(),
        ]);
        TaskTemplate::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
            'name' => 'Never used', 'last_used_at' => null,
        ]);

        $names = $this->as($this->user)
            ->getJson('/api/v1/task-templates')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Daily', 'Rarely', 'Never used'], $names);
        unset($old, $fresh);
    }
}
