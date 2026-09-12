<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class DepartmentMemberTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Rady']);
        $this->tenant = app(WorkspaceService::class)->create($this->owner, 'Acme');
        $this->owner->refresh();

        $this->department = Department::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operations',
        ]);
    }

    protected function member(string $role = 'member', string $name = 'Sophea'): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(WorkspaceService::class)->addMember($this->tenant, $user, $role);

        return $user->refresh();
    }

    public function test_an_admin_can_put_someone_in_a_department(): void
    {
        $sophea = $this->member();

        Livewire::actingAs($this->owner)
            ->test('pages::departments')
            ->set("newMember.{$this->department->id}", (string) $sophea->id)
            ->call('addMember', $this->department->id);

        $this->assertTrue($this->department->members()->whereKey($sophea->id)->exists());
        $this->assertSame('member', $this->department->members()->find($sophea->id)->pivot->role);
    }

    public function test_a_member_can_be_made_the_lead_and_removed_again(): void
    {
        $sophea = $this->member();

        $component = Livewire::actingAs($this->owner)
            ->test('pages::departments')
            ->set("newMember.{$this->department->id}", (string) $sophea->id)
            ->call('addMember', $this->department->id)
            ->call('setMemberRole', $this->department->id, $sophea->id, 'lead');

        $this->assertSame('lead', $this->department->members()->find($sophea->id)->pivot->role);

        $component->call('removeMember', $this->department->id, $sophea->id);

        $this->assertFalse($this->department->members()->whereKey($sophea->id)->exists());
    }

    public function test_a_plain_member_cannot_change_who_is_in_a_department(): void
    {
        $sophea = $this->member();
        $dara = $this->member('member', 'Dara');

        Livewire::actingAs($dara)
            ->test('pages::departments')
            ->set("newMember.{$this->department->id}", (string) $sophea->id)
            ->call('addMember', $this->department->id)
            ->assertForbidden();

        $this->assertSame(0, $this->department->members()->count());
    }

    /** A department belongs to one workspace, so its people must too. */
    public function test_someone_outside_the_workspace_cannot_be_added(): void
    {
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->create($outsider, 'Other Co');

        $this->assertThrows(
            fn () => app(DepartmentService::class)->addMember($this->department, $outsider->refresh()),
            InvalidArgumentException::class,
        );

        $this->assertSame(0, $this->department->members()->count());
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $sophea = $this->member();

        $this->assertThrows(
            fn () => app(DepartmentService::class)->addMember($this->department, $sophea, 'owner'),
            InvalidArgumentException::class,
        );
    }

    /* ----------------------------------------------------------------- API */

    public function test_the_api_lists_adds_and_removes_members(): void
    {
        $sophea = $this->member();

        // The API is JWT, not the session guard; the guard also resolves its
        // user once per test, so the header goes on before the first request.
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($this->owner));

        $this->postJson("/api/v1/departments/{$this->department->id}/members", ['user_id' => $sophea->id, 'role' => 'lead'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sophea')
            // The department pivot, not the workspace one.
            ->assertJsonPath('data.department_role', 'lead');

        $this->getJson("/api/v1/departments/{$this->department->id}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/departments/{$this->department->id}/members/{$sophea->id}")->assertOk();

        $this->assertSame(0, $this->department->members()->count());
    }

    public function test_the_api_refuses_a_non_admin(): void
    {
        $sophea = $this->member();
        $dara = $this->member('member', 'Dara');

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($dara))
            ->postJson("/api/v1/departments/{$this->department->id}/members", ['user_id' => $sophea->id])
            ->assertForbidden();
    }
}
