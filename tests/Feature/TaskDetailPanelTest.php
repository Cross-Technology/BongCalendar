<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskDetailPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    protected function makeTask(User $user, array $attributes = []): Task
    {
        return Task::factory()->create($attributes + [
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
        ]);
    }

    public function test_clicking_a_task_opens_it_in_the_sidebar(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user, ['title' => 'Collect the delivery', 'note' => 'Ring the bell']);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->assertSet('taskId', $task->id)
            ->assertSet('detail_title', 'Collect the delivery')
            ->assertSet('detail_note', 'Ring the bell')
            ->assertSee('Collect the delivery');
    }

    public function test_a_task_id_in_the_url_opens_the_sidebar_on_load(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user, ['title' => 'Count the till']);

        $this->actingAs($user)
            ->get('/dashboard?taskId='.$task->id)
            ->assertOk()
            ->assertSee('Count the till')
            ->assertSee('Subtasks');
    }

    public function test_editing_the_fields_saves_on_blur(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);
        $department = Department::factory()->create(['tenant_id' => $user->current_tenant_id]);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('detail_title', 'Renamed task')
            ->set('detail_description', 'Ring twice')
            ->set('detail_note', 'Left the key under the mat')
            ->set('detail_due', '2026-09-01T09:00')
            ->set('detail_department_id', $department->id)
            ->set('detail_assignee_id', $user->id)
            ->assertHasNoErrors();

        $fresh = $task->fresh();

        $this->assertSame('Renamed task', $fresh->title);
        $this->assertSame('Ring twice', $fresh->description);
        $this->assertSame('Left the key under the mat', $fresh->note);
        $this->assertSame($department->id, $fresh->department_id);
        $this->assertSame($user->id, $fresh->assignee_id);
        $this->assertSame(
            '2026-09-01 09:00',
            $fresh->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i')
        );
    }

    public function test_an_emptied_title_is_put_back_rather_than_saved(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user, ['title' => 'Keep me']);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('detail_title', '   ')
            ->assertSet('detail_title', 'Keep me');

        $this->assertSame('Keep me', $task->fresh()->title);
    }

    public function test_status_priority_and_due_date_can_be_changed_from_the_panel(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user, ['due_date' => CarbonImmutable::now()]);

        $component = Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id);

        $component->call('setTaskStatus', $task->id, 'in_progress');
        $this->assertSame('in_progress', $task->fresh()->status);

        $component->call('setTaskPriority', $task->id, 'urgent');
        $this->assertSame('urgent', $task->fresh()->priority);

        $component->call('clearTaskDue', $task->id)->assertSet('detail_due', '');
        $this->assertNull($task->fresh()->due_date);
    }

    public function test_subtasks_can_be_added_from_the_panel(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user, ['priority' => 'high']);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('newSubtaskTitle', 'Check the crates')
            ->call('addSubtask')
            ->assertHasNoErrors()
            ->assertSet('newSubtaskTitle', '');

        $subtask = Task::where('title', 'Check the crates')->firstOrFail();

        $this->assertSame($task->id, $subtask->parent_task_id);
        $this->assertSame('high', $subtask->priority, 'a subtask inherits its parent priority');
    }

    public function test_deleting_the_open_task_closes_the_panel(): void
    {
        $user = $this->makeUser();
        $task = $this->makeTask($user);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->call('deleteTask', $task->id)
            ->assertSet('taskId', null);

        $this->assertSoftDeleted($task);
    }

    public function test_a_task_from_another_workspace_cannot_be_opened(): void
    {
        $user = $this->makeUser();
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        $foreign = Task::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
        ]);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('selectTask', $foreign->id)
            ->assertForbidden();
    }
}
