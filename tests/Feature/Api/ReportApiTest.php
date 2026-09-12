<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected Department $department;

    protected string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();

        $this->department = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);

        $this->today = now()->toDateString();

        $this->actingAsApi($this->user);
    }

    /** The guard resolves its user once per test, so call before any request. */
    protected function actingAsApi(User $user): void
    {
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }

    protected function member(string $role = 'member'): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $user, $role);

        return $user->refresh();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'department_id' => $this->department->id,
            'report_date' => $this->today,
            'body' => '<div>Closed the month. <strong>All good.</strong></div>',
        ], $overrides);
    }

    public function test_a_department_report_can_be_written_and_read_back(): void
    {
        $this->postJson('/api/v1/reports', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.department_id', $this->department->id)
            ->assertJsonPath('data.report_date', $this->today)
            ->assertJsonPath('data.author_id', $this->user->id)
            // The plain-text rendering is stored beside the markup.
            ->assertJsonPath('data.body_text', 'Closed the month. All good.');

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.department_id', $this->department->id);
    }

    /**
     * A department has one report per day, so writing again corrects it
     * rather than stacking a second or tripping the unique index.
     */
    public function test_writing_twice_in_a_day_updates_the_same_report(): void
    {
        $this->postJson('/api/v1/reports', $this->payload())->assertCreated();

        $this->postJson('/api/v1/reports', $this->payload(['body' => '<div>Actually, stock is short.</div>']))
            ->assertOk()
            ->assertJsonPath('data.body_text', 'Actually, stock is short.');

        $this->assertSame(1, Report::count());
    }

    public function test_the_original_author_is_kept_when_someone_else_edits(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
            'report_date' => $this->today,
        ]);

        $editor = $this->member();
        $this->actingAsApi($editor);

        $this->patchJson("/api/v1/reports/{$report->id}", ['body' => '<div>Tidied up.</div>'])
            ->assertOk()
            // The byline does not silently change hands.
            ->assertJsonPath('data.author_id', $this->user->id)
            ->assertJsonPath('data.last_editor_id', $editor->id);
    }

    /**
     * The body is rich text from a browser editor rendered back as markup to
     * the whole workspace — the textbook stored-XSS setup.
     */
    public function test_dangerous_markup_never_survives_into_storage(): void
    {
        $this->postJson('/api/v1/reports', $this->payload([
            'body' => '<div>Fine</div>'
                .'<script>alert(1)</script>'
                .'<img src=x onerror=alert(1)>'
                .'<iframe src="//evil.test"></iframe>'
                .'<a href="javascript:alert(1)">click</a>'
                .'<div onclick="alert(1)">nope</div>'
                .'<a href="https://ok.test">ok</a>',
        ]))->assertCreated();

        $body = Report::firstOrFail()->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('javascript:', $body);

        // Legitimate formatting and links come through untouched.
        $this->assertStringContainsString('Fine', $body);
        $this->assertStringContainsString('https://ok.test', $body);
    }

    public function test_an_editor_left_untouched_is_not_a_report(): void
    {
        $this->postJson('/api/v1/reports', $this->payload(['body' => '<div><br></div>']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, Report::count());
    }

    public function test_the_daily_roll_call_counts_who_is_missing(): void
    {
        Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        $this->postJson('/api/v1/reports', $this->payload())->assertCreated();

        $this->getJson("/api/v1/reports/daily?date={$this->today}")
            ->assertOk()
            ->assertJsonPath('data.date', $this->today)
            ->assertJsonPath('data.departments_total', 2)
            ->assertJsonPath('data.reported', 1)
            ->assertJsonPath('data.missing', 1)
            ->assertJsonCount(2, 'data.rows');
    }

    public function test_a_report_cannot_be_filed_against_another_workspaces_department(): void
    {
        $outsider = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($outsider, 'Other Co');
        $theirDepartment = Department::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->postJson('/api/v1/reports', $this->payload(['department_id' => $theirDepartment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_reports_do_not_leak_across_workspaces(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
        ]);

        $outsider = User::factory()->create();
        app(WorkspaceService::class)->create($outsider, 'Other Co');

        $this->actingAsApi($outsider->refresh());

        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/reports/{$report->id}")->assertForbidden();
    }

    public function test_any_member_may_correct_a_report_but_only_the_author_or_an_admin_deletes_it(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
            'report_date' => $this->today,
        ]);

        $member = $this->member();
        $this->actingAsApi($member);

        $this->patchJson("/api/v1/reports/{$report->id}", ['body' => '<div>Correction.</div>'])->assertOk();
        $this->deleteJson("/api/v1/reports/{$report->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_a_report(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
            'report_date' => $this->today,
        ]);

        $this->actingAsApi($this->member('admin'));

        $this->deleteJson("/api/v1/reports/{$report->id}")->assertOk();
        $this->assertSoftDeleted('reports', ['id' => $report->id]);
    }

    /** A deleted day must not lock the department out of reporting again. */
    public function test_a_day_can_be_rewritten_after_its_report_is_deleted(): void
    {
        $this->postJson('/api/v1/reports', $this->payload())->assertCreated();

        Report::firstOrFail()->delete();

        $this->postJson('/api/v1/reports', $this->payload(['body' => '<div>Second attempt.</div>']))
            ->assertCreated()
            ->assertJsonPath('data.body_text', 'Second attempt.');
    }

    public function test_reports_can_be_filtered_by_day_and_searched(): void
    {
        Report::factory()->on($this->today)->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
            'body' => '<div>Stock count finished</div>',
            'body_text' => 'Stock count finished',
        ]);

        $yesterday = now()->subDay()->toDateString();
        Report::factory()->on($yesterday)->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->user->id,
            'body' => '<div>Deliveries late</div>',
            'body_text' => 'Deliveries late',
        ]);

        $this->getJson("/api/v1/reports?date={$yesterday}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body_text', 'Deliveries late');

        $this->getJson('/api/v1/reports?q=Stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body_text', 'Stock count finished');
    }
}
