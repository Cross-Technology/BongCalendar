<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme', 'Asia/Phnom_Penh');
        $this->user->refresh();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->user));
    }

    public function test_a_task_is_created_with_the_mobile_defaults(): void
    {
        $task = $this->postJson('/api/v1/tasks', ['title' => 'Open the shop'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.priority', 'medium')
            ->assertJsonPath('data.status_meta.label', 'Todo')
            ->assertJsonPath('data.completed_at', null)
            ->json('data');

        $this->assertSame($this->tenant->id, $task['tenant_id']);
        $this->assertSame($this->user->id, $task['created_by']);
    }

    public function test_moving_a_task_to_done_stamps_completed_at_and_back_clears_it(): void
    {
        $task = Task::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertNotNull($task->fresh()->completed_at);

        $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertNull($task->fresh()->completed_at, 'leaving done should clear the stamp');
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $task = Task::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_updating_through_the_resource_route_also_maintains_completed_at(): void
    {
        $task = Task::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->patchJson("/api/v1/tasks/{$task->id}", ['status' => 'done', 'title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.status', 'done');

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_the_board_can_be_filtered_by_status_and_department(): void
    {
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);

        Task::factory()->status('in_progress')->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
            'department_id' => $department->id, 'title' => 'Wanted',
        ]);
        Task::factory()->status('todo')->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id, 'title' => 'Wrong status',
        ]);
        Task::factory()->status('in_progress')->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id, 'title' => 'Wrong department',
        ]);

        $this->getJson('/api/v1/tasks?status[]=in_progress&department='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Wanted');
    }

    public function test_tasks_from_another_workspace_are_invisible(): void
    {
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        Task::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
            'title' => 'Not yours',
        ]);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_assignee_must_belong_to_the_workspace(): void
    {
        $stranger = User::factory()->create();

        $this->postJson('/api/v1/tasks', ['title' => 'Nope', 'assignee_id' => $stranger->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');
    }

    public function test_subtasks_are_limited_to_one_level(): void
    {
        $parent = Task::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
        ]);

        $child = $this->postJson('/api/v1/tasks', [
            'title' => 'Child', 'parent_task_id' => $parent->id,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/tasks', [
            'title' => 'Grandchild', 'parent_task_id' => $child['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_task_id');
    }

    public function test_the_index_returns_top_level_tasks_only_by_default(): void
    {
        $parent = Task::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id, 'title' => 'Parent',
        ]);
        Task::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
            'parent_task_id' => $parent->id, 'title' => 'Child',
        ]);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subtasks_count', 1);
    }

    public function test_only_the_creator_assignee_or_an_admin_may_delete(): void
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');
        $member->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        $task = Task::factory()->create([
            'tenant_id' => $this->tenant->id, 'created_by' => $this->user->id,
        ]);

        app('tymon.jwt')->unsetToken();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($member->fresh()))
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertForbidden();

        // ...but any member may move it across the board.
        app('tymon.jwt')->unsetToken();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($member->fresh()))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertOk();
    }
}
