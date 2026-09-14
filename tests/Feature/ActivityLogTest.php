<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Report;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Dara', 'timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();

        $this->department = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);
    }

    protected function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->addMember($this->tenant, $user, 'member');

        return $user->refresh();
    }

    /* ---------------------------------------------------------------- tasks */

    public function test_creating_a_task_records_who_made_it(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
        ]);

        $entry = $task->activities()->firstOrFail();

        $this->assertSame('created', $entry->action);
        $this->assertSame($this->owner->id, $entry->user_id);
        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame('Dara', $entry->actorName());
    }

    public function test_the_saves_that_finish_a_creation_do_not_read_as_edits(): void
    {
        $this->actingAs($this->owner);

        // The board creates a task, then stamps its status and syncs its
        // owners — one act, three saves.
        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Count the till',
        ]);
        $task->setStatus('in_progress');
        $task->syncAssignees([$this->owner->id]);

        $this->assertSame(1, $task->activities()->count());
        $this->assertSame('created', $task->activities()->first()->action);
    }

    public function test_editing_a_task_records_the_editor_and_what_changed(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
            'priority' => 'medium',
        ]);

        // A second person, in a request of their own.
        $editor = $this->member('Sokha');
        $this->actingAs($editor);

        Task::find($task->id)->update(['title' => 'Open the shop early', 'priority' => 'urgent']);

        $entry = $task->activities()->where('action', 'updated')->firstOrFail();

        $this->assertSame($editor->id, $entry->user_id);
        $this->assertSame('Sokha', $entry->actorName());

        $lines = collect($entry->changeLines())->keyBy('label');

        $this->assertSame('Open the shop', $lines['Title']['from']);
        $this->assertSame('Open the shop early', $lines['Title']['to']);
        $this->assertSame('Medium', $lines['Priority']['from']);
        $this->assertSame('Urgent', $lines['Priority']['to']);
    }

    public function test_a_status_change_is_recorded_by_its_label_not_its_code(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Restock the fridge',
        ]);

        Task::find($task->id)->setStatus('done');

        $lines = collect($task->activities()->where('action', 'updated')->firstOrFail()->changeLines());

        $this->assertSame(['Status'], $lines->pluck('label')->all());
        $this->assertSame('Todo', $lines->first()['from']);
        $this->assertSame('Completed', $lines->first()['to']);
    }

    public function test_an_assignee_is_recorded_as_a_name(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Cash up',
        ]);

        $sokha = $this->member('Sokha');
        Task::find($task->id)->syncAssignees([$sokha->id]);

        $lines = collect($task->activities()->where('action', 'updated')->firstOrFail()->changeLines());

        $this->assertSame('Sokha', $lines->firstWhere('label', 'Assignee')['to']);
    }

    public function test_board_housekeeping_is_not_an_edit(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Sweep up',
        ]);

        Task::find($task->id)->update(['position' => 7]);

        $this->assertSame(0, $task->activities()->where('action', 'updated')->count());
    }

    public function test_one_edit_made_over_several_saves_is_one_entry(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
        ]);

        // What an API PATCH does: the plain fields, then the status, then the
        // owners — one edit, three saves.
        $same = Task::find($task->id);
        $same->update(['title' => 'Open the shop early']);
        $same->setStatus('in_progress');
        $same->setStatus('done');

        $entries = $task->activities()->where('action', 'updated')->get();

        $this->assertCount(1, $entries);

        $lines = collect($entries->first()->changeLines())->keyBy('label');

        $this->assertSame('Open the shop early', $lines['Title']['to']);
        // The status moved twice; the entry says where it started and where it
        // ended, not every step between.
        $this->assertSame('Todo', $lines['Status']['from']);
        $this->assertSame('Completed', $lines['Status']['to']);
    }

    public function test_a_change_undone_in_the_same_edit_leaves_nothing_behind(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
        ]);

        $same = Task::find($task->id);
        $same->update(['title' => 'Typo']);
        $same->update(['title' => 'Open the shop']);

        $this->assertSame(0, $task->activities()->where('action', 'updated')->count());
    }

    public function test_deleting_a_task_is_recorded(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Chase the invoice',
        ]);

        Task::find($task->id)->delete();

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'action' => 'deleted',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_a_task_written_with_nobody_signed_in_is_recorded_as_system(): void
    {
        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Nightly rollover',
        ]);

        $entry = $task->activities()->firstOrFail();

        $this->assertNull($entry->user_id);
        $this->assertSame('System', $entry->actorName());
    }

    /* -------------------------------------------------------------- reports */

    public function test_writing_and_editing_a_report_records_both_people(): void
    {
        $day = CarbonImmutable::parse('2026-09-10');

        $this->actingAs($this->owner);
        $report = app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Quiet day.</p>'
        );

        $sokha = $this->member('Sokha');
        $this->actingAs($sokha);
        app(ReportService::class)->write(
            $this->tenant, $this->department, $sokha, $day, '<p>Quiet day. Two deliveries came late.</p>'
        );

        $entries = Report::find($report->id)->activities()->with('user')->get();

        $this->assertSame(['updated', 'created'], $entries->pluck('action')->all());
        $this->assertSame('Sokha', $entries->first()->actorName());
        $this->assertSame('Dara', $entries->last()->actorName());
    }

    public function test_a_report_body_is_recorded_as_rewritten_rather_than_copied(): void
    {
        $day = CarbonImmutable::parse('2026-09-10');

        $this->actingAs($this->owner);
        $report = app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>First draft.</p>'
        );
        app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Second draft, much longer.</p>'
        );

        $lines = collect(Report::find($report->id)->activities()->where('action', 'updated')->firstOrFail()->changeLines());
        $body = $lines->firstWhere('label', 'Report');

        $this->assertTrue($body['opaque']);
        $this->assertNull($body['from']);
        $this->assertNull($body['to']);

        // The editor is the entry's actor; repeating it as a field would say
        // the same thing twice.
        $this->assertNull($lines->firstWhere('label', 'Last editor'));
    }

    public function test_saving_a_report_unchanged_records_nothing(): void
    {
        $day = CarbonImmutable::parse('2026-09-10');

        $this->actingAs($this->owner);
        $report = app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Quiet day.</p>'
        );
        app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Quiet day.</p>'
        );

        $this->assertSame(0, Report::find($report->id)->activities()->where('action', 'updated')->count());
    }

    /* ------------------------------------------------------------------- ui */

    public function test_the_task_panel_shows_who_created_the_task_and_its_history(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Collect the delivery',
        ]);

        $sokha = $this->member('Sokha');
        $this->actingAs($sokha);
        Task::find($task->id)->setStatus('done');

        Livewire::actingAs($this->owner)
            ->test('pages::dashboard')
            ->call('selectTask', $task->id)
            ->assertSee('Created by')
            ->assertSee('Dara')
            ->assertSee('History')
            // The entry Sokha left, with the field that changed.
            ->assertSee('Sokha')
            ->assertSee('Status');
    }

    public function test_the_task_edit_modal_shows_the_creator(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Reorder the cups',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::tasks')
            ->call('edit', $task->id)
            ->assertSee('Created by')
            ->assertSee('Dara');
    }

    public function test_the_report_dialog_shows_who_wrote_it_and_who_edited_it(): void
    {
        $day = CarbonImmutable::parse('2026-09-10');

        $this->actingAs($this->owner);
        $report = app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Quiet day.</p>'
        );

        $sokha = $this->member('Sokha');
        $this->actingAs($sokha);
        app(ReportService::class)->write(
            $this->tenant, $this->department, $sokha, $day, '<p>Quiet day. Two deliveries came late.</p>'
        );

        Livewire::actingAs($this->owner)
            ->test('pages::reports', ['date' => '2026-09-10'])
            ->call('openReport', $report->id)
            ->assertSee('Written by')
            ->assertSee('Dara')
            ->assertSee('History')
            ->assertSee('Sokha');
    }

    /* ------------------------------------------------------------------ api */

    public function test_the_api_sends_a_tasks_trail_with_its_detail(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Open the shop',
        ]);
        Task::find($task->id)->update(['priority' => 'urgent']);

        $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();

        $activity = $response->json('data.activity');

        $this->assertCount(2, $activity);
        $this->assertSame('updated', $activity[0]['action']);
        $this->assertSame('edited', $activity[0]['action_label']);
        $this->assertSame('Dara', $activity[0]['actor_name']);
        $this->assertSame('Priority', $activity[0]['changes'][0]['label']);
        $this->assertSame('Urgent', $activity[0]['changes'][0]['to']);
        $this->assertSame('created', $activity[1]['action']);
    }

    public function test_the_api_sends_a_reports_trail_with_its_detail(): void
    {
        $day = CarbonImmutable::parse('2026-09-10');

        $this->actingAs($this->owner);
        $report = app(ReportService::class)->write(
            $this->tenant, $this->department, $this->owner, $day, '<p>Quiet day.</p>'
        );

        $activity = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner))
            ->getJson("/api/v1/reports/{$report->id}")
            ->assertOk()
            ->json('data.activity');

        $this->assertCount(1, $activity);
        $this->assertSame('created', $activity[0]['action']);
        $this->assertSame('Dara', $activity[0]['actor_name']);
    }

    public function test_the_trail_survives_the_thing_it_describes(): void
    {
        $this->actingAs($this->owner);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->owner->id,
            'title' => 'Chase the invoice',
        ]);

        Task::find($task->id)->forceDelete();

        // A deleted task is exactly the one somebody asks about afterwards.
        $this->assertSame(2, Activity::where('subject_id', $task->id)
            ->where('subject_type', Task::class)
            ->count());
    }
}
