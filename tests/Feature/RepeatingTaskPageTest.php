<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepeatingTaskPageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    public function test_a_daily_repeat_creates_one_task_per_day(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'Open the shop')
            ->set('form_priority', 'high')
            ->set('form_due_date', '2026-09-01T09:00')
            ->set('form_repeat', 'daily')
            ->set('form_repeat_until', '2026-09-07')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $tasks = Task::orderBy('due_date')->get();

        $this->assertCount(7, $tasks);
        $this->assertCount(1, $tasks->pluck('series_id')->unique());
        $this->assertSame(
            '2026-09-01 09:00',
            $tasks->first()->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-09-07 09:00',
            $tasks->last()->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
    }

    public function test_the_modal_says_how_many_it_will_create(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_due_date', '2026-09-01T09:00')
            ->set('form_repeat', 'weekdays')
            ->set('form_repeat_until', '2026-09-30')
            ->assertSee('22');
    }

    public function test_a_repeat_needs_an_end_date(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'Forever')
            ->set('form_repeat', 'daily')
            ->call('save')
            ->assertHasErrors('form_repeat_until');

        $this->assertSame(0, Task::count());
    }

    public function test_without_a_repeat_exactly_one_task_is_made(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'One off')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Task::count());
        $this->assertNull(Task::firstOrFail()->series_id);
    }

    public function test_completing_one_occurrence_leaves_the_rest_alone(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'Count the till')
            ->set('form_due_date', '2026-09-01T09:00')
            ->set('form_repeat', 'daily')
            ->set('form_repeat_until', '2026-09-03')
            ->call('save');

        $first = Task::orderBy('due_date')->firstOrFail();

        Livewire::actingAs($user)
            ->test('pages::tasks')
            ->call('toggleDone', $first->id);

        $this->assertSame('done', $first->fresh()->status);
        $this->assertSame(2, Task::where('status', 'todo')->count());
    }
}
