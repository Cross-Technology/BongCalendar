<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Narrowing the board: by words, by department, by who is on it, and by when
 * the work lands.
 */
class TaskBoardFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();
    }

    protected function task(string $title, array $attributes = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => $title,
            'status' => 'todo',
            'priority' => 'medium',
        ], $attributes));
    }

    /** Titles the board is showing, across every column. */
    protected function board(array $set = []): array
    {
        $component = Livewire::actingAs($this->owner)->test('pages::tasks');

        foreach ($set as $property => $value) {
            $component->set($property, $value);
        }

        return collect($component->viewData('columns'))
            ->flatten()
            ->pluck('title')
            ->sort()
            ->values()
            ->all();
    }

    public function test_search_matches_title_and_description(): void
    {
        $this->task('Open the shop');
        $this->task('Count the till', ['description' => 'Including the shop float']);
        $this->task('Call the landlord');

        $this->assertSame(['Count the till', 'Open the shop'], $this->board(['search' => 'shop']));
    }

    public function test_a_percent_sign_in_the_search_box_is_not_a_wildcard(): void
    {
        $this->task('Raise prices 5%');
        $this->task('Call the landlord');

        $this->assertSame(['Raise prices 5%'], $this->board(['search' => '5%']));
    }

    public function test_today_uses_the_day_the_work_is_scheduled_for(): void
    {
        $tz = 'Asia/Phnom_Penh';
        $today = CarbonImmutable::now($tz);

        $this->task('Starts today', ['start_date' => $today->setTime(9, 0)]);
        // No start day, so its deadline is the day it lands on.
        $this->task('Due today', ['due_date' => $today->setTime(17, 0)]);
        $this->task('Starts tomorrow', ['start_date' => $today->addDay()->setTime(9, 0)]);
        $this->task('No date at all');

        $this->assertSame(['Due today', 'Starts today'], $this->board(['due' => 'today']));
    }

    public function test_the_week_window_covers_the_next_seven_days(): void
    {
        $today = CarbonImmutable::now('Asia/Phnom_Penh');

        $this->task('In three days', ['start_date' => $today->addDays(3)->setTime(9, 0)]);
        $this->task('In six days', ['start_date' => $today->addDays(6)->setTime(9, 0)]);
        $this->task('In nine days', ['start_date' => $today->addDays(9)->setTime(9, 0)]);

        $this->assertSame(['In six days', 'In three days'], $this->board(['due' => 'week']));
    }

    public function test_overdue_is_about_deadlines_only(): void
    {
        $today = CarbonImmutable::now('Asia/Phnom_Penh');

        $this->task('Late', ['due_date' => $today->subDays(2)->setTime(17, 0)]);
        $this->task('Late but finished', ['due_date' => $today->subDays(2)->setTime(17, 0), 'status' => 'done']);
        // Started days ago with no deadline: late is not a thing it can be.
        $this->task('Started ages ago', ['start_date' => $today->subDays(5)->setTime(9, 0)]);

        $this->assertSame(['Late'], $this->board(['due' => 'overdue']));
    }

    public function test_no_date_finds_the_unscheduled(): void
    {
        $today = CarbonImmutable::now('Asia/Phnom_Penh');

        $this->task('Someday');
        $this->task('Scheduled', ['start_date' => $today->setTime(9, 0)]);
        $this->task('Has a deadline', ['due_date' => $today->setTime(17, 0)]);

        $this->assertSame(['Someday'], $this->board(['due' => 'none']));
    }

    public function test_a_chosen_day_narrows_to_that_day(): void
    {
        $tz = 'Asia/Phnom_Penh';
        $day = CarbonImmutable::now($tz)->addDays(4);

        $this->task('On the day', ['start_date' => $day->setTime(9, 0)]);
        $this->task('The day before', ['start_date' => $day->subDay()->setTime(9, 0)]);

        $this->assertSame(['On the day'], $this->board(['onDate' => $day->format('Y-m-d')]));
    }

    public function test_a_chosen_day_and_a_chip_do_not_both_apply(): void
    {
        $tz = 'Asia/Phnom_Penh';
        $day = CarbonImmutable::now($tz)->addDays(4);

        $this->task('On the day', ['start_date' => $day->setTime(9, 0)]);

        // Picking a day clears the chip, rather than quietly ANDing the two
        // into a window that can only ever be empty.
        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->set('due', 'today')
            ->set('onDate', $day->format('Y-m-d'))
            ->assertSet('due', '')
            ->assertSee('On the day');
    }

    public function test_the_chips_toggle_off_when_pressed_again(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('setDue', 'today')
            ->assertSet('due', 'today')
            ->call('setDue', 'today')
            ->assertSet('due', '');
    }

    public function test_clearing_puts_every_task_back(): void
    {
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->task('Open the shop')->syncDepartments([$department->id]);
        $this->task('Call the landlord');

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->set('search', 'shop')
            ->set('department', $department->id)
            ->set('due', 'today')
            ->assertSet('search', 'shop')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('department', null)
            ->assertSet('due', '')
            ->assertSee('Call the landlord');
    }

    public function test_blocked_takes_a_column_only_when_something_is_blocked(): void
    {
        $this->task('Open the shop');

        $columns = fn () => array_keys(Livewire::actingAs($this->owner)->test('pages::tasks')->viewData('columns'));

        $this->assertSame(['todo', 'in_progress', 'done'], $columns());

        $this->task('Stuck', ['status' => 'blocked']);

        $this->assertSame(['todo', 'in_progress', 'done', 'blocked'], $columns());
    }

    public function test_the_header_counts_what_is_hidden(): void
    {
        $this->task('Open the shop');
        $this->task('Call the landlord');
        $this->task('Count the till');

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->set('search', 'shop')
            ->assertSet('search', 'shop')
            ->assertSeeInOrder(['1', 'of 3 tasks']);
    }
}
