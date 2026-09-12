<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Assigning a task to someone files it under their department.
 */
class TaskDepartmentFilingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme');
        $this->owner->refresh();

        $this->operations = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);
    }

    protected function member(string $name = 'Sophea'): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(WorkspaceService::class)->addMember($this->tenant, $user, 'member');

        return $user->refresh();
    }

    protected function task(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
            'status' => 'todo',
            'priority' => 'medium',
        ], $attributes));
    }

    public function test_a_task_lands_in_its_assignees_department(): void
    {
        $sophea = $this->member();
        app(DepartmentService::class)->addMember($this->operations, $sophea);

        $task = $this->task(['assignee_id' => $sophea->id]);

        $this->assertSame($this->operations->id, $task->department_id);
    }

    /** A deliberate filing outranks the assignee's default. */
    public function test_a_department_already_chosen_is_never_overwritten(): void
    {
        $sophea = $this->member();
        app(DepartmentService::class)->addMember($this->operations, $sophea);

        $sales = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        $task = $this->task(['assignee_id' => $sophea->id, 'department_id' => $sales->id]);

        $this->assertSame($sales->id, $task->department_id);
    }

    public function test_reassigning_does_not_move_a_task_that_already_has_a_home(): void
    {
        $sophea = $this->member();
        $dara = $this->member('Dara');

        $sales = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);
        app(DepartmentService::class)->addMember($this->operations, $sophea);
        app(DepartmentService::class)->addMember($sales, $dara);

        $task = $this->task(['assignee_id' => $sophea->id]);
        $this->assertSame($this->operations->id, $task->department_id);

        $task->update(['assignee_id' => $dara->id]);

        $this->assertSame($this->operations->id, $task->fresh()->department_id);
    }

    public function test_an_assignee_in_no_department_leaves_the_task_unfiled(): void
    {
        $task = $this->task(['assignee_id' => $this->member()->id]);

        $this->assertNull($task->department_id);
    }

    public function test_an_unassigned_task_stays_unfiled(): void
    {
        $this->assertNull($this->task()->department_id);
    }

    /** Someone in several departments files work under the one they lead. */
    public function test_the_department_they_lead_wins(): void
    {
        $sophea = $this->member();
        $sales = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        app(DepartmentService::class)->addMember($this->operations, $sophea, 'member');
        app(DepartmentService::class)->addMember($sales, $sophea, 'lead');

        $task = $this->task(['assignee_id' => $sophea->id]);

        $this->assertSame($sales->id, $task->department_id);
    }

    public function test_it_applies_when_a_task_is_created_through_the_api(): void
    {
        $sophea = $this->member();
        app(DepartmentService::class)->addMember($this->operations, $sophea);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->postJson('/api/v1/tasks', [
                'title' => 'Count the float',
                'assignee_id' => $sophea->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.department_id', $this->operations->id);
    }
}
