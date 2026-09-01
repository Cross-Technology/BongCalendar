<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class TaskStartDateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($this->user, 'Acme', 'Asia/Phnom_Penh');
        $this->user->refresh();
    }

    protected function asApi(): static
    {
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->user->fresh()));
    }

    public function test_the_api_saves_and_returns_a_start_date(): void
    {
        $task = $this->asApi()
            ->postJson('/api/v1/tasks', [
                'title' => 'Stocktake',
                'start_date' => '2026-09-01T09:00:00+07:00',
                'due_date' => '2026-09-05T17:00:00+07:00',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($task['start_date'], 'the start date must come back, not vanish');

        $saved = Task::firstOrFail();

        $this->assertSame(
            '2026-09-01 09:00',
            $saved->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-09-05 17:00',
            $saved->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
    }

    public function test_the_start_date_can_be_changed_and_cleared(): void
    {
        $task = Task::factory()->create([
            'tenant_id' => $this->user->current_tenant_id,
            'created_by' => $this->user->id,
            'start_date' => CarbonImmutable::parse('2026-09-01T09:00:00+07:00'),
        ]);

        $this->asApi()
            ->patchJson("/api/v1/tasks/{$task->id}", ['start_date' => '2026-09-04T08:00:00+07:00'])
            ->assertOk();

        $this->assertSame(
            '2026-09-04 08:00',
            $task->fresh()->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );

        $this->asApi()
            ->patchJson("/api/v1/tasks/{$task->id}", ['start_date' => null])
            ->assertOk();

        $this->assertNull($task->fresh()->start_date);
    }

    public function test_the_calendar_places_a_task_by_its_start_date(): void
    {
        $day = CarbonImmutable::now('Asia/Phnom_Penh')->startOfMonth()->addDays(3);

        // Scheduled for one day, due much later: it belongs on the start day.
        Task::factory()->create([
            'tenant_id' => $this->user->current_tenant_id,
            'created_by' => $this->user->id,
            'title' => 'Scheduled work',
            'start_date' => $day->setTime(9, 0)->utc(),
            'due_date' => $day->addDays(20)->setTime(17, 0)->utc(),
        ]);

        $this->actingAs($this->user)
            ->get('/dashboard?day='.$day->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Scheduled work');
    }

    public function test_a_task_with_only_a_due_date_still_appears(): void
    {
        $day = CarbonImmutable::now('Asia/Phnom_Penh')->startOfMonth()->addDays(5);

        Task::factory()->create([
            'tenant_id' => $this->user->current_tenant_id,
            'created_by' => $this->user->id,
            'title' => 'Deadline only',
            'start_date' => null,
            'due_date' => $day->setTime(9, 0)->utc(),
        ]);

        $this->actingAs($this->user)
            ->get('/dashboard?day='.$day->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Deadline only');
    }

    public function test_quick_add_stamps_the_open_day_as_the_start_date(): void
    {
        $day = CarbonImmutable::now('Asia/Phnom_Penh')->startOfMonth()->addDays(8);

        Livewire::actingAs($this->user)
            ->test('pages::dashboard')
            ->set('day', $day->format('Y-m-d'))
            ->set('newTaskTitle', 'Sweep the yard')
            ->call('addTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Sweep the yard')->firstOrFail();

        $this->assertNotNull($task->start_date);
        $this->assertNull($task->due_date, 'a day on the calendar is a schedule, not a deadline');
        $this->assertSame(
            $day->format('Y-m-d'),
            $task->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d'),
        );
    }

    public function test_a_repeat_walks_the_start_date_and_keeps_the_gap_to_the_deadline(): void
    {
        $this->asApi()
            ->postJson('/api/v1/tasks', [
                'title' => 'Weekly report',
                'start_date' => '2026-09-01T09:00:00+07:00',
                'due_date' => '2026-09-03T17:00:00+07:00',
                'repeat' => ['frequency' => 'daily', 'count' => 3],
            ])
            ->assertCreated()
            ->assertJsonPath('meta.created', 3);

        $tasks = Task::orderBy('start_date')->get();

        $this->assertSame(
            ['2026-09-01 09:00', '2026-09-02 09:00', '2026-09-03 09:00'],
            $tasks->map(fn (Task $t) => $t->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'))->all(),
        );

        // Two days and eight hours after each start, every time.
        $this->assertSame(
            ['2026-09-03 17:00', '2026-09-04 17:00', '2026-09-05 17:00'],
            $tasks->map(fn (Task $t) => $t->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'))->all(),
        );
    }

    public function test_the_board_modal_saves_a_start_date(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::tasks')
            ->call('create')
            ->set('form_title', 'Deep clean')
            ->set('form_start_date', '2026-09-02T08:00')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Deep clean')->firstOrFail();

        $this->assertSame(
            '2026-09-02 08:00',
            $task->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
    }

    public function test_the_detail_panel_edits_the_start_date(): void
    {
        $task = Task::factory()->create([
            'tenant_id' => $this->user->current_tenant_id,
            'created_by' => $this->user->id,
        ]);

        Livewire::actingAs($this->user)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->set('detail_start', '2026-09-10T07:30')
            ->assertHasNoErrors();

        $this->assertSame(
            '2026-09-10 07:30',
            $task->fresh()->start_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
    }
}
