<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample data is what a new user looks at first, so it has to actually
 * demonstrate the thing: departments with people, tasks with progress, and a
 * report already written.
 */
class SampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function seedOwner(): User
    {
        $owner = User::factory()->create(['email' => 'rady@example.com']);
        app(WorkspaceService::class)->create($owner, 'My Workspace', 'Asia/Phnom_Penh');

        return $owner->refresh();
    }

    public function test_it_fills_a_workspace_with_a_worked_example(): void
    {
        $owner = $this->seedOwner();
        $tenantId = $owner->current_tenant_id;

        $this->seed(SampleDataSeeder::class);

        $departments = Department::forTenant($tenantId)->with('members')->get();

        $this->assertCount(4, $departments);
        $this->assertEqualsCanonicalizing(
            ['Operations', 'Sales', 'Kitchen', 'Admin'],
            $departments->pluck('name')->all(),
        );

        // Every department has people in it, or the Reports page has no story.
        foreach ($departments as $department) {
            $this->assertGreaterThan(0, $department->members->count(), "{$department->name} has nobody in it.");
        }

        $this->assertTrue($departments->contains(fn ($d) => $d->members->contains(fn ($m) => $m->pivot->role === 'lead')));

    }

    public function test_the_tasks_carry_real_progress_to_report_on(): void
    {
        $owner = $this->seedOwner();

        $this->seed(SampleDataSeeder::class);

        $tasks = Task::forTenant($owner->current_tenant_id)->withCount('checklist')->get();

        $this->assertGreaterThanOrEqual(12, $tasks->count());

        // A spread of statuses and priorities, not twelve identical rows.
        $this->assertGreaterThan(2, $tasks->pluck('status')->unique()->count());
        $this->assertGreaterThan(2, $tasks->pluck('priority')->unique()->count());

        // Every task explains itself, and most carry a checklist.
        $this->assertTrue($tasks->every(fn (Task $t) => filled($t->description)));
        $this->assertTrue($tasks->every(fn (Task $t) => $t->department_id !== null));
        $this->assertGreaterThanOrEqual(6, $tasks->where('checklist_count', '>', 0)->count());

        // Part-ticked, so the report shows progress rather than 0/n everywhere.
        $partly = Task::has('checklist')->get()->first(
            fn (Task $t) => $t->checklist()->where('completed', true)->exists()
                && $t->checklist()->where('completed', false)->exists()
        );

        $this->assertNotNull($partly, 'No task is partly ticked, so no progress is visible.');
    }

    public function test_it_writes_example_reports(): void
    {
        $this->seedOwner();

        $this->seed(SampleDataSeeder::class);

        $this->assertSame(2, Report::count());
        $this->assertStringContainsString('<ul>', Report::first()->body.Report::skip(1)->first()->body);
    }

    /** Running it twice must not double everything. */
    public function test_it_is_safe_to_run_again(): void
    {
        $this->seedOwner();

        $this->seed(SampleDataSeeder::class);
        $counts = [Department::count(), Task::count(), Report::count(), User::count()];

        $this->seed(SampleDataSeeder::class);

        $this->assertSame($counts, [Department::count(), Task::count(), Report::count(), User::count()]);
    }
}
