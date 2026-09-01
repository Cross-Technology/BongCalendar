<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    public function test_the_board_groups_tasks_by_status(): void
    {
        $user = $this->makeUser();

        foreach (['todo' => 'Sweep up', 'in_progress' => 'Count the till', 'done' => 'Lock the door'] as $status => $title) {
            Task::factory()->status($status)->create([
                'tenant_id' => $user->current_tenant_id,
                'created_by' => $user->id,
                'title' => $title,
            ]);
        }

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk()
            ->assertSee('Todo')
            ->assertSee('In Progress')
            ->assertSee('Completed')
            ->assertSee('Sweep up')
            ->assertSee('Count the till')
            ->assertSee('Lock the door');
    }

    public function test_moving_a_task_across_the_board_maintains_completed_at(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->create([
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('setStatus', $task->id, 'done');

        $this->assertSame('done', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('setStatus', $task->id, 'blocked');

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_the_completion_toggle_flips_between_done_and_todo(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->create([
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
        ]);

        $component = Livewire::actingAs($user)->test('pages::tasks');

        $component->call('toggleDone', $task->id);
        $this->assertSame('done', $task->fresh()->status);

        $component->call('toggleDone', $task->id);
        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_a_task_in_another_workspace_cannot_be_touched(): void
    {
        $user = $this->makeUser();
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        $foreign = Task::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
        ]);

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('setStatus', $foreign->id, 'done')
            ->assertForbidden();

        $this->assertSame('todo', $foreign->fresh()->status);
    }

    public function test_the_dashboard_shows_tasks_due_on_the_open_day_and_can_add_one(): void
    {
        $user = $this->makeUser();
        $day = CarbonImmutable::now('Asia/Phnom_Penh')->startOfMonth()->addDays(4);

        Task::factory()->dueOn($day->setTime(9, 0)->utc())->create([
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
            'title' => 'Collect the delivery',
        ]);

        $this->actingAs($user)
            ->get('/dashboard?day='.$day->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Collect the delivery')
            ->assertSee('1 task');

        Livewire::actingAs($user)
            ->test('pages::dashboard', ['day' => $day->format('Y-m-d')])
            ->set('day', $day->format('Y-m-d'))
            ->set('newTaskTitle', 'Refill the fridge')
            ->call('addTask')
            ->assertHasNoErrors()
            ->assertSet('newTaskTitle', '');

        $added = Task::where('title', 'Refill the fridge')->firstOrFail();

        // Adding from a day on the calendar schedules the work for that day
        // rather than setting a deadline, so it lands on start_date.
        $this->assertSame(
            $day->format('Y-m-d'),
            $added->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d'),
            'the quick-add box should date the task to the open day'
        );
    }

    public function test_the_dashboard_completion_toggle_works(): void
    {
        $user = $this->makeUser();
        $task = Task::factory()->dueOn(now())->create([
            'tenant_id' => $user->current_tenant_id,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('toggleTask', $task->id);

        $this->assertSame('done', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }
}
