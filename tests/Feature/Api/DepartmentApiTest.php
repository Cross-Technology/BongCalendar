<?php

namespace Tests\Feature\Api;

use App\Models\Calendar;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme');
        $this->user->refresh();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->user));
    }

    public function test_a_department_can_be_created_and_listed(): void
    {
        $this->postJson('/api/v1/departments', [
            'name' => 'Errands',
            'color' => '#10b981',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Errands')
            ->assertJsonPath('data.slug', 'errands')
            ->assertJsonPath('data.tenant_id', $this->tenant->id);

        $this->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Errands')
            ->assertJsonPath('data.0.calendars_count', 0);
    }

    public function test_names_must_be_unique_within_a_workspace_only(): void
    {
        Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        $this->postJson('/api/v1/departments', ['name' => 'Sales'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // The same name in a different workspace is fine.
        $other = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($other, 'Other Co');
        Department::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Sales']);

        $this->assertSame(2, Department::where('name', 'Sales')->count());
    }

    public function test_a_calendar_can_be_filed_under_a_department(): void
    {
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);

        $calendar = $this->postJson('/api/v1/calendars', [
            'name' => 'Releases',
            'department_id' => $department->id,
        ])->assertCreated()->json('data');

        $this->assertSame($department->id, $calendar['department_id']);

        $this->getJson('/api/v1/calendars?department='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Releases');
    }

    public function test_a_department_from_another_workspace_is_rejected(): void
    {
        $other = User::factory()->create();
        $otherTenant = app(WorkspaceService::class)->create($other, 'Other Co');
        $foreign = Department::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->postJson('/api/v1/calendars', [
            'name' => 'Releases',
            'department_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_deleting_a_department_keeps_its_calendars(): void
    {
        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
        $calendar = Calendar::factory()->create([
            'tenant_id' => $this->tenant->id,
            'owner_id' => $this->user->id,
            'department_id' => $department->id,
        ]);

        $this->deleteJson('/api/v1/departments/'.$department->id)->assertOk();

        $this->assertSoftDeleted($department);
        $this->assertNotNull($calendar->fresh(), 'the calendar should survive');
        $this->assertNull($calendar->fresh()->department_id, 'it should fall back to ungrouped');
    }

    public function test_only_workspace_admins_may_manage_departments(): void
    {
        $member = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $member, 'member');
        $member->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        $department = Department::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($member->fresh()))
            ->postJson('/api/v1/departments', ['name' => 'Nope'])
            ->assertForbidden();

        app('tymon.jwt')->unsetToken();

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($member->fresh()))
            ->deleteJson('/api/v1/departments/'.$department->id)
            ->assertForbidden();
    }
}
