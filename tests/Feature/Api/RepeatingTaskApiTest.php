<?php

namespace Tests\Feature\Api;

use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RepeatingTaskApiTest extends TestCase
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

    public function test_a_daily_task_for_a_whole_month_creates_one_task_per_day(): void
    {
        $response = $this->postJson('/api/v1/tasks', [
            'title' => 'Open the shop',
            'priority' => 'high',
            'due_date' => '2026-09-01T09:00:00+07:00',
            'repeat' => ['frequency' => 'daily', 'until' => '2026-09-30'],
        ])->assertCreated();

        $response->assertJsonPath('meta.created', 30);
        $this->assertSame(30, Task::count());

        $series = Task::pluck('series_id')->unique();
        $this->assertCount(1, $series, 'every occurrence shares one series id');

        // Each occurrence is an ordinary task: its own status and checklist.
        $tasks = Task::orderBy('due_date')->get();
        $this->assertSame('Open the shop', $tasks->first()->title);
        $this->assertSame('high', $tasks->first()->priority);
        $this->assertSame(
            '2026-09-30 09:00',
            $tasks->last()->due_date->setTimezone('Asia/Phnom_Penh')->format('Y-m-d H:i'),
        );
    }

    public function test_occurrences_are_completed_independently(): void
    {
        $this->postJson('/api/v1/tasks', [
            'title' => 'Count the till',
            'due_date' => '2026-09-01T09:00:00+07:00',
            'repeat' => ['frequency' => 'daily', 'count' => 3],
        ])->assertCreated();

        $first = Task::orderBy('due_date')->first();

        $this->patchJson("/api/v1/tasks/{$first->id}/status", ['status' => 'done'])->assertOk();

        $this->assertSame('done', $first->fresh()->status);
        $this->assertSame(
            2,
            Task::where('status', 'todo')->count(),
            'ticking one day off leaves the rest alone'
        );
    }

    public function test_a_single_task_still_creates_one_task(): void
    {
        $this->postJson('/api/v1/tasks', ['title' => 'One off'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'One off')
            ->assertJsonPath('data.series_id', null);

        $this->assertSame(1, Task::count());
    }

    public function test_the_preview_says_how_many_will_be_created(): void
    {
        $this->postJson('/api/v1/tasks/repeat-preview', [
            'frequency' => 'weekdays',
            'due_date' => '2026-09-01T09:00:00+07:00',
            'until' => '2026-09-30',
        ])->assertOk()->assertJsonPath('data.count', 22);
    }

    public function test_a_repeat_without_an_end_is_refused(): void
    {
        $this->postJson('/api/v1/tasks', [
            'title' => 'Forever',
            'repeat' => ['frequency' => 'daily'],
        ])->assertUnprocessable();

        $this->assertSame(0, Task::count());
    }

    public function test_a_series_is_capped_rather_than_running_away(): void
    {
        $this->postJson('/api/v1/tasks', [
            'title' => 'Ten years of this',
            'due_date' => '2026-01-01T09:00:00+07:00',
            'repeat' => ['frequency' => 'daily', 'until' => '2036-01-01'],
        ])->assertCreated()->assertJsonPath('meta.created', 366);
    }

    public function test_the_whole_series_can_be_deleted_at_once(): void
    {
        $series = $this->postJson('/api/v1/tasks', [
            'title' => 'Daily standup',
            'due_date' => '2026-09-01T09:00:00+07:00',
            'repeat' => ['frequency' => 'daily', 'count' => 5],
        ])->assertCreated()->json('meta.series_id');

        $this->deleteJson("/api/v1/task-series/{$series}")
            ->assertOk()
            ->assertJsonPath('meta.deleted', 5);

        $this->assertSame(0, Task::count());
    }

    public function test_another_workspaces_series_cannot_be_deleted(): void
    {
        $stranger = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($stranger, 'Other Co');

        $series = (string) \Illuminate\Support\Str::uuid();

        Task::factory()->count(3)->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $stranger->id,
            'series_id' => $series,
        ]);

        $this->deleteJson("/api/v1/task-series/{$series}")->assertNotFound();

        $this->assertSame(3, Task::count());
    }
}
