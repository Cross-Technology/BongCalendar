<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\ReportService;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Each department styles its own report header. It is stored once and rendered
 * above every report it files, with its fields filled in at render time.
 */
class ReportHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Rady', 'timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme', 'Asia/Phnom_Penh');
        $this->owner->refresh();

        $this->department = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);
    }

    protected function member(string $name = 'Sophea', bool $inDepartment = true): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(WorkspaceService::class)->addMember($this->tenant, $user, 'member');
        $user->refresh();

        if ($inDepartment) {
            app(DepartmentService::class)->addMember($this->department, $user);
        }

        return $user;
    }

    public function test_a_department_member_can_design_the_header(): void
    {
        $sophea = $this->member();

        Livewire::actingAs($sophea)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id)
            ->assertSet('headerDepartmentId', $this->department->id)
            ->set('header_body', '<h1>{{department}} — Daily Report</h1><div>{{date}} · {{author}}</div>')
            ->call('saveHeader')
            ->assertSet('headerDepartmentId', null);

        $this->assertStringContainsString('{{department}}', $this->department->fresh()->report_header);
    }

    /** Membership is the point: it is their department's style. */
    public function test_someone_outside_the_department_cannot_design_its_header(): void
    {
        $dara = $this->member('Dara', inDepartment: false);

        Livewire::actingAs($dara)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id)
            ->assertForbidden();
    }

    public function test_a_workspace_admin_can_design_it_without_being_in_the_department(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id)
            ->assertSet('headerDepartmentId', $this->department->id);
    }

    public function test_the_fields_fill_themselves_in(): void
    {
        $this->department->update([
            'report_header' => '<h1>{{department}} — {{workspace}}</h1><div>{{date}} · prepared by {{author}}</div>',
        ]);

        $rendered = app(ReportService::class)->renderHeader(
            $this->department->fresh()->load('tenant'),
            CarbonImmutable::parse('2026-09-15'),
            $this->owner,
        );

        $this->assertStringContainsString('Operations — Acme', $rendered);
        $this->assertStringContainsString('Tuesday, 15 September 2026', $rendered);
        $this->assertStringContainsString('prepared by Rady', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    /** A Report's date casts to Carbon, not CarbonImmutable. */
    public function test_it_renders_from_a_stored_reports_own_date(): void
    {
        $this->department->update(['report_header' => '<div>{{date}}</div>']);

        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => '2026-09-15',
        ]);

        $rendered = app(ReportService::class)->renderHeader(
            $this->department->fresh(),
            $report->fresh()->report_date,
            $this->owner,
        );

        $this->assertStringContainsString('15 September 2026', $rendered);
    }

    public function test_the_header_is_shown_above_the_report_when_reading_it(): void
    {
        $this->department->update(['report_header' => '<h1>{{department}} — Daily Report</h1>']);

        $report = Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $this->department->id,
            'author_id' => $this->owner->id,
            'report_date' => now($this->tenant->timezone)->toDateString(),
            'body' => '<div>Opened on time.</div>',
            'body_text' => 'Opened on time.',
        ]);

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('openReport', $report->id)
            ->assertSee('Operations — Daily Report')
            ->assertSee('Opened on time.');
    }

    public function test_header_markup_is_sanitised_like_any_other_rich_text(): void
    {
        app(ReportService::class)->saveHeader(
            $this->department,
            '<h1>Safe</h1><script>alert(1)</script><img src=x onerror=alert(1)>',
        );

        $header = $this->department->fresh()->report_header;

        $this->assertStringNotContainsString('<script', $header);
        $this->assertStringNotContainsString('<img', $header);
        $this->assertStringContainsString('Safe', $header);
    }

    /** Field values go into stored markup, so they are escaped on the way in. */
    public function test_a_department_name_with_markup_stays_text(): void
    {
        $this->department->update([
            'name' => 'Sales & <Ops>',
            'report_header' => '<h1>{{department}}</h1>',
        ]);

        $rendered = app(ReportService::class)->renderHeader(
            $this->department->fresh(),
            CarbonImmutable::parse('2026-09-15'),
            $this->owner,
        );

        $this->assertStringNotContainsString('<Ops>', $rendered);
        $this->assertStringContainsString('&lt;Ops&gt;', $rendered);
    }

    public function test_clearing_the_editor_removes_the_header(): void
    {
        $this->department->update(['report_header' => '<h1>Operations</h1>']);

        app(ReportService::class)->saveHeader($this->department, '<div><br></div>');

        $this->assertNull($this->department->fresh()->report_header);
    }

    /**
     * The fields are only useful if it is obvious what they do. The designer
     * shows each one next to the value it will produce, and inserts it in the
     * browser rather than through the server.
     */
    public function test_the_designer_shows_what_each_field_becomes(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id)
            // The token itself…
            ->assertSee('{{department}}')
            ->assertSee('{{workspace}}')
            // …beside the value it resolves to.
            ->assertSee('Operations')
            ->assertSee('Acme')
            ->assertSee('Rady')
            ->assertSee(now($this->tenant->timezone)->translatedFormat('l, j F Y'))
            // Inserted client-side, so it costs no round trip.
            ->assertSee("\$dispatch('rich-text-insert'", escape: false);
    }

    /** Escaped for markup on insertion, but shown plainly in the designer. */
    public function test_an_example_with_markup_reads_as_text_in_the_designer(): void
    {
        $this->department->update(['name' => 'Sales & <Ops>']);

        Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id)
            ->assertSee('Sales & <Ops>');
    }

    /**
     * Reopening the designer after a save must show what was saved.
     *
     * The editor sits behind wire:ignore, so Livewire leaves its DOM alone —
     * and with a repeating wire:key the morph reuses the element that is
     * already there, Alpine never re-runs x-init, and Quill keeps the text it
     * held before. The symptom is a header that reverts to the old one every
     * time it is edited.
     */
    public function test_reopening_the_designer_rebuilds_the_editor(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test('pages::reports')
            ->call('editHeader', $this->department->id);

        $firstKey = $this->editorKey($component->html());

        $component->set('header_body', '<h2>Second version</h2>')
            ->call('saveHeader')
            ->call('editHeader', $this->department->id);

        $secondKey = $this->editorKey($component->html());

        $this->assertNotSame(
            $firstKey,
            $secondKey,
            'The editor must get a new wire:key per opening, or the old one is reused with its old text.',
        );

        // And the rebuilt editor is handed the text that was just saved.
        $component->assertSet('header_body', '<h2>Second version</h2>')
            ->assertSee('Second version');
    }

    protected function editorKey(string $html): string
    {
        preg_match('/wire:key="(header-editor-[^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches, 'The header editor should carry a wire:key.');

        return $matches[1];
    }

    public function test_the_api_saves_and_clears_a_header(): void
    {
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner));

        $this->putJson("/api/v1/departments/{$this->department->id}/report-header", [
            'report_header' => '<h1>{{department}}</h1>',
        ])->assertOk()->assertJsonPath('data.report_header', '<h1>{{department}}</h1>');

        $this->putJson("/api/v1/departments/{$this->department->id}/report-header", [])
            ->assertOk()
            ->assertJsonPath('data.report_header', null);
    }
}
