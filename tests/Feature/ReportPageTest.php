<?php

namespace Tests\Feature;

use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Report;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();

        $this->department = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);
    }

    protected function member(): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $user, 'member');

        return $user->refresh();
    }

    public function test_the_page_lists_every_department_and_who_has_not_reported(): void
    {
        Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        $this->actingAs($this->owner)
            ->get('/reports')
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('Sales')
            ->assertSee('Not yet')
            ->assertSee('0 of 2 departments reported');
    }

    public function test_a_report_can_be_written_in_the_editor(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->assertSet('writingDepartmentId', $this->department->id)
            ->set('form_body', '<div>Stock count done. <strong>All matched.</strong></div>')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('writingDepartmentId', null);

        $report = Report::firstOrFail();

        $this->assertSame($this->department->id, $report->department_id);
        $this->assertSame($this->owner->id, $report->author_id);
        $this->assertStringContainsString('<strong>All matched.</strong>', $report->body);
        $this->assertSame('Stock count done. All matched.', $report->body_text);
    }

    public function test_the_rich_text_editor_is_rendered_and_shielded_from_livewire(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->assertSee('rich-editor', escape: false)
            ->assertSee('quillEditor(', escape: false)
            // Without wire:ignore, Livewire morphs the subtree the editor owns
            // and wipes what is being typed.
            ->assertSee('wire:ignore', escape: false);
    }

    public function test_the_editor_strips_dangerous_markup_before_saving(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->set('form_body', '<div>Fine</div><script>alert(1)</script><a href="javascript:alert(1)">x</a>')
            ->call('save')
            ->assertHasNoErrors();

        $body = Report::firstOrFail()->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringContainsString('Fine', $body);
    }

    public function test_an_empty_editor_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            // What an untouched editor sends.
            ->set('form_body', '<div><br></div>')
            ->call('save')
            ->assertHasErrors('form_body')
            ->assertSet('writingDepartmentId', $this->department->id);

        $this->assertSame(0, Report::count());
    }

    public function test_opening_an_existing_report_loads_it_for_editing(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => now($this->tenant->timezone)->toDateString(),
            'body' => '<div>First draft</div>',
            'body_text' => 'First draft',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->assertSet('form_body', '<div>First draft</div>')
            ->set('form_body', '<div>Second draft</div>')
            ->call('save');

        // Corrected in place rather than filed as a second report for the day.
        $this->assertSame(1, Report::count());
        $this->assertSame('Second draft', $report->fresh()->body_text);
    }

    /**
     * The editor reads the body from Livewire at mount rather than from a
     * rendered attribute. That keeps quotes and apostrophes out of the markup
     * entirely, and means a reused element cannot show stale text.
     */
    public function test_an_existing_body_is_not_baked_into_the_markup(): void
    {
        $body = '<p>She said "it\'s done" &amp; left</p><ul><li>one</li></ul>';

        Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => now($this->tenant->timezone)->toDateString(),
            'body' => $body,
            'body_text' => 'She said "it\'s done" & left one',
        ]);

        $html = Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->assertSet('form_body', $body)
            ->html();

        preg_match('/x-data="(quillEditor\([^"]*\))"/', $html, $matches);

        $this->assertNotEmpty($matches, 'The editor should carry an x-data attribute.');

        // The body never travels through the DOM — the editor reads it from
        // Livewire at mount, so there is no attribute to break or go stale.
        // (It still appears elsewhere on the page, in the row's excerpt.)
        $this->assertStringNotContainsString('She said', $matches[1]);
        $this->assertStringNotContainsString('&lt;p&gt;', $matches[1]);
    }

    public function test_a_report_opens_in_a_read_dialog(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => now($this->tenant->timezone)->toDateString(),
            'body' => '<div>Everything shipped</div>',
            'body_text' => 'Everything shipped',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('openReport', $report->id)
            ->assertSet('viewingId', $report->id)
            ->assertSee('Everything shipped')
            ->call('closeReport')
            ->assertSet('viewingId', null);
    }

    public function test_the_day_can_be_stepped_through(): void
    {
        $component = Livewire::actingAs($this->owner)->test('pages::reports');

        $today = $component->get('date');

        $component->call('shiftDay', -1)
            ->assertSet('date', date('Y-m-d', strtotime($today.' -1 day')))
            ->call('goToToday')
            ->assertSet('date', $today);
    }

    /** A hand-edited ?date= must not blow the page up. */
    public function test_a_nonsense_date_falls_back_to_today(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports', ['date' => 'not-a-date'])
            ->assertOk()
            ->assertSet('date', now($this->tenant->timezone)->toDateString());

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->set('date', '2026-02-31')
            ->assertSet('date', now($this->tenant->timezone)->toDateString());
    }

    /** A task on the report's day, with a part-ticked checklist. */
    protected function task(string $title = 'Open the shop', string $status = 'in_progress'): Task
    {
        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'created_by' => $this->owner->id,
            'assignee_id' => $this->owner->id,
            'title' => $title,
            'status' => $status,
            'priority' => 'high',
            'start_date' => now($this->tenant->timezone)->setTime(9, 0)->toIso8601String(),
        ]);

        ChecklistItem::create(['task_id' => $task->id, 'title' => 'Unlock', 'completed' => true, 'position' => 0]);
        ChecklistItem::create(['task_id' => $task->id, 'title' => 'Count float', 'completed' => true, 'position' => 1]);
        ChecklistItem::create(['task_id' => $task->id, 'title' => 'Fridge temps', 'completed' => false, 'position' => 2]);

        return $task;
    }

    public function test_the_day_shows_what_each_department_was_working_on(): void
    {
        $this->task();

        $this->actingAs($this->owner)
            ->get('/reports')
            ->assertOk()
            ->assertSee('Open the shop')
            // Two of three checklist items ticked.
            ->assertSee('2/3');
    }

    public function test_the_editor_lists_the_days_tasks_with_their_progress(): void
    {
        $this->task();

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->assertSee('Open the shop')
            ->assertSee('2/3')
            ->assertSee('High')
            ->assertSee('Add all');
    }

    /**
     * The task goes to the browser as an event, not into the property: the
     * editor is behind wire:ignore, so a server-side write would be invisible.
     */
    public function test_a_task_can_be_written_into_the_report(): void
    {
        $task = $this->task();

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->call('insertTask', $task->id)
            ->assertDispatched(
                'rich-text-insert',
                fn (string $event, array $params) => str_contains($params['html'], 'Open the shop')
                    && str_contains($params['html'], 'In Progress')
                    && str_contains($params['html'], '2/3 checklist'),
            );
    }

    public function test_every_task_can_be_written_in_at_once(): void
    {
        $this->task('Open the shop');
        $this->task('Weekly stock count', 'todo');

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->call('insertAllTasks')
            ->assertDispatched(
                'rich-text-insert',
                fn (string $event, array $params) => str_starts_with($params['html'], '<ul>')
                    && str_contains($params['html'], 'Open the shop')
                    && str_contains($params['html'], 'Weekly stock count'),
            );
    }

    /** Titles are user input on their way into markup. */
    public function test_a_task_title_is_escaped_on_its_way_into_the_report(): void
    {
        $task = $this->task('<script>alert(1)</script>');

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('startWriting', $this->department->id)
            ->call('insertTask', $task->id)
            ->assertDispatched(
                'rich-text-insert',
                fn (string $event, array $params) => ! str_contains($params['html'], '<script>')
                    && str_contains($params['html'], '&lt;script&gt;'),
            );
    }

    public function test_a_member_cannot_delete_someone_elses_report(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => now($this->tenant->timezone)->toDateString(),
        ]);

        Livewire::actingAs($this->member())
            ->test('pages::reports')
            ->call('deleteReport', $report->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('reports', ['id' => $report->id]);
    }

    public function test_a_department_from_another_workspace_cannot_be_written_for(): void
    {
        $outsider = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($outsider, 'Other Co');
        $theirDepartment = Department::factory()->create(['tenant_id' => $otherTenant->id]);

        // Department lookups are tenant-scoped, so this never resolves.
        $this->assertThrows(
            fn () => Livewire::actingAs($this->owner)
                ->test('pages::reports')
                ->call('startWriting', $theirDepartment->id),
            ModelNotFoundException::class,
        );

        $this->assertSame(0, Report::count());
    }
}
