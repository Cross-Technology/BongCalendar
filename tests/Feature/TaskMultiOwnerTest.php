<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * A task can be filed under several departments and picked up by several
 * people. `department_id` / `assignee_id` stay as the first of each set, so
 * every read that shows one name still shows the same one.
 */
class TaskMultiOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $sales;

    protected Department $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Rady', 'timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();

        $this->sales = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);
        $this->operations = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Operations']);
    }

    protected function member(string $name): User
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

    /* ------------------------------------------------------------- the model */

    public function test_a_task_can_be_filed_under_several_departments(): void
    {
        $task = $this->task();

        $task->syncDepartments([$this->sales->id, $this->operations->id]);

        $this->assertEqualsCanonicalizing(
            [$this->sales->id, $this->operations->id],
            $task->departments()->pluck('departments.id')->all()
        );

        // The first pick is the one the single-name column carries.
        $this->assertSame($this->sales->id, $task->fresh()->department_id);
    }

    public function test_several_people_can_be_on_one_task(): void
    {
        $sophea = $this->member('Sophea');
        $dara = $this->member('Dara');
        $task = $this->task();

        $task->syncAssignees([$sophea->id, $dara->id]);

        $this->assertEqualsCanonicalizing(
            [$sophea->id, $dara->id],
            $task->assignees()->pluck('users.id')->all()
        );
        $this->assertSame($sophea->id, $task->fresh()->assignee_id);
    }

    public function test_clearing_the_set_clears_the_primary_too(): void
    {
        $task = $this->task(['department_id' => $this->sales->id]);

        $task->syncDepartments([]);

        $this->assertNull($task->fresh()->department_id);
        $this->assertSame(0, $task->departments()->count());
    }

    public function test_a_plain_write_of_the_primary_column_reaches_the_set(): void
    {
        // The API, a template and a subtask all write the column directly.
        $task = $this->task(['department_id' => $this->sales->id]);

        $this->assertSame([$this->sales->id], $task->departments()->pluck('departments.id')->all());

        $task->update(['department_id' => $this->operations->id]);

        $this->assertSame([$this->operations->id], $task->departments()->pluck('departments.id')->all());
    }

    public function test_a_derived_department_lands_in_the_set(): void
    {
        // Sophea works in Operations, so an unfiled task handed to her is
        // filed there — and the set has to agree with the column.
        $sophea = $this->member('Sophea');
        $this->operations->members()->attach($sophea->id);

        $task = $this->task();
        $task->syncAssignees([$sophea->id]);

        $this->assertSame($this->operations->id, $task->fresh()->department_id);
        $this->assertSame([$this->operations->id], $task->departments()->pluck('departments.id')->all());
    }

    public function test_filters_match_any_member_of_the_set(): void
    {
        $sophea = $this->member('Sophea');
        $dara = $this->member('Dara');

        $shared = $this->task(['title' => 'Stocktake']);
        $shared->syncDepartments([$this->sales->id, $this->operations->id]);
        $shared->syncAssignees([$sophea->id, $dara->id]);

        $salesOnly = $this->task(['title' => 'Call the supplier']);
        $salesOnly->syncDepartments([$this->sales->id]);

        $this->assertEqualsCanonicalizing(
            ['Stocktake', 'Call the supplier'],
            Task::inDepartments([$this->sales->id])->pluck('title')->all()
        );
        $this->assertSame(['Stocktake'], Task::inDepartments([$this->operations->id])->pluck('title')->all());

        // Dara is second on the task, and the filter still finds it.
        $this->assertSame(['Stocktake'], Task::assignedTo([$dara->id])->pluck('title')->all());
        $this->assertSame(['Call the supplier'], Task::unassigned()->pluck('title')->all());
    }

    public function test_anyone_on_the_task_may_delete_it(): void
    {
        $sophea = $this->member('Sophea');
        $dara = $this->member('Dara');

        $task = $this->task();
        $task->syncAssignees([$sophea->id, $dara->id]);

        $this->assertTrue($dara->can('delete', $task));
        $this->assertFalse($this->member('Vuthy')->can('delete', $task));
    }

    /* -------------------------------------------------------------- the board */

    public function test_the_board_form_saves_both_sets(): void
    {
        $sophea = $this->member('Sophea');
        $dara = $this->member('Dara');

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'Stocktake')
            // Checkbox values arrive as strings, the way the browser sends them.
            ->set('form_department_ids', [(string) $this->sales->id, (string) $this->operations->id])
            ->set('form_assignee_ids', [(string) $sophea->id, (string) $dara->id])
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Stocktake')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$this->sales->id, $this->operations->id],
            $task->departments()->pluck('departments.id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$sophea->id, $dara->id],
            $task->assignees()->pluck('users.id')->all()
        );
    }

    public function test_editing_a_task_shows_the_sets_it_already_has(): void
    {
        $sophea = $this->member('Sophea');
        $task = $this->task();
        $task->syncDepartments([$this->sales->id, $this->operations->id]);
        $task->syncAssignees([$sophea->id]);

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('edit', $task->id)
            ->assertSet('form_department_ids', [$this->sales->id, $this->operations->id])
            ->assertSet('form_assignee_ids', [$sophea->id]);
    }

    public function test_the_picker_names_what_is_picked_without_waiting_for_the_browser(): void
    {
        /*
         * The trigger is written by Alpine, but it is also rendered server-side
         * — without that it comes back blank after every re-render, because the
         * server's copy of the markup has no idea what the browser put there.
         */
        $sophea = $this->member('Sophea');
        $task = $this->task();
        $task->syncDepartments([$this->sales->id, $this->operations->id]);
        $task->syncAssignees([$sophea->id]);

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('edit', $task->id)
            ->assertSeeHtml('>Sales, Operations</span>')
            ->assertSeeHtml('>Sophea</span>');
    }

    public function test_the_picker_says_unfiled_when_nothing_is_picked(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('create')
            ->assertSeeHtml('>Unfiled</span>')
            ->assertSeeHtml('>Unassigned</span>');
    }

    public function test_a_shared_task_shows_on_both_departments_boards(): void
    {
        $task = $this->task(['title' => 'Stocktake']);
        $task->syncDepartments([$this->sales->id, $this->operations->id]);

        foreach ([$this->sales, $this->operations] as $department) {
            Livewire::actingAs($this->owner)
                ->test('pages::tasks', ['department' => $department->id])
                ->assertSee('Stocktake');
        }
    }

    public function test_a_shared_task_counts_for_every_department_it_is_in(): void
    {
        $this->task(['title' => 'Stocktake'])->syncDepartments([$this->sales->id, $this->operations->id]);

        $this->assertSame(1, $this->sales->tasks()->count());
        $this->assertSame(1, $this->operations->tasks()->count());
    }

    public function test_a_shared_task_is_listed_under_both_departments_on_the_day(): void
    {
        $task = $this->task([
            'title' => 'Stocktake',
            'start_date' => now()->setTime(9, 0),
        ]);
        $task->syncDepartments([$this->sales->id, $this->operations->id]);

        $groups = Livewire::actingAs($this->owner)
            ->test('pages::dashboard')
            ->viewData('taskGroups');

        $this->assertEqualsCanonicalizing(
            ['Sales', 'Operations'],
            $groups->pluck('department.name')->all()
        );

        foreach ($groups as $group) {
            $this->assertSame(['Stocktake'], $group['tasks']->pluck('title')->all());
        }
    }

    /* ---------------------------------------------------------------- the API */

    public function test_the_api_takes_and_returns_both_sets(): void
    {
        $sophea = $this->member('Sophea');
        $dara = $this->member('Dara');
        $token = JWTAuth::fromUser($this->owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/tasks', [
                'title' => 'Stocktake',
                'department_ids' => [$this->sales->id, $this->operations->id],
                'assignee_ids' => [$sophea->id, $dara->id],
            ])
            ->assertCreated();

        $taskId = $response->json('data.id');

        $this->assertEqualsCanonicalizing(
            [$this->sales->id, $this->operations->id],
            Task::findOrFail($taskId)->departments()->pluck('departments.id')->all()
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks/'.$taskId)
            ->assertOk()
            // The first of each set is what the single-name fields carry.
            ->assertJsonPath('data.department_id', $this->sales->id)
            ->assertJsonPath('data.assignee_id', $sophea->id)
            ->assertJsonCount(2, 'data.department_ids')
            ->assertJsonCount(2, 'data.assignees');
    }

    public function test_the_api_filter_takes_a_list(): void
    {
        $token = JWTAuth::fromUser($this->owner);

        $this->task(['title' => 'Stocktake'])->syncDepartments([$this->operations->id]);
        $this->task(['title' => 'Call the supplier'])->syncDepartments([$this->sales->id]);
        $this->task(['title' => 'Unfiled']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/tasks?department[]='.$this->sales->id.'&department[]='.$this->operations->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_update_that_says_nothing_about_owners_leaves_them_alone(): void
    {
        $sophea = $this->member('Sophea');
        $task = $this->task();
        $task->syncAssignees([$sophea->id, $this->owner->id]);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->patchJson('/api/v1/tasks/'.$task->id, ['title' => 'Renamed'])
            ->assertOk();

        $this->assertSame(2, $task->assignees()->count());
    }

    public function test_a_lone_assignee_id_still_works(): void
    {
        $sophea = $this->member('Sophea');
        $task = $this->task();
        $task->syncAssignees([$sophea->id, $this->owner->id]);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->patchJson('/api/v1/tasks/'.$task->id, ['assignee_id' => $this->owner->id])
            ->assertOk()
            ->assertJsonPath('data.assignee_id', $this->owner->id);

        // One name sent means a set of one: Sophea is off the task.
        $this->assertSame([$this->owner->id], $task->assignees()->pluck('users.id')->all());
    }

    public function test_a_department_from_another_workspace_is_refused(): void
    {
        $stranger = User::factory()->create();
        $other = app(WorkspaceService::class)->create($stranger, 'Other');
        $theirs = Department::factory()->create(['tenant_id' => $other->id]);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->postJson('/api/v1/tasks', ['title' => 'Snoop', 'department_ids' => [$theirs->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_ids.0');
    }
}
