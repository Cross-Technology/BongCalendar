<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\Department;
use App\Models\Event;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentPageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOwner(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    public function test_the_page_lists_departments_and_ungrouped_calendars(): void
    {
        $user = $this->makeOwner();
        Department::factory()->create(['tenant_id' => $user->current_tenant_id, 'name' => 'Errands']);

        $this->actingAs($user)
            ->get('/departments')
            ->assertOk()
            ->assertSee('Errands')
            ->assertSee('Ungrouped calendars')
            ->assertSee('My Calendar');
    }

    public function test_an_admin_can_create_and_fill_a_department(): void
    {
        $user = $this->makeOwner();
        $calendar = $user->calendars()->firstOrFail();

        Livewire::actingAs($user)
            ->test('pages::departments')
            ->call('create')
            ->set('form_name', 'Operations')
            ->set('form_color', '#10b981')
            ->call('save')
            ->assertSet('showModal', false)
            ->assertHasNoErrors();

        $department = Department::where('name', 'Operations')->firstOrFail();
        $this->assertSame($user->current_tenant_id, $department->tenant_id);

        Livewire::actingAs($user)
            ->test('pages::departments')
            ->call('moveCalendar', $calendar->id, $department->id);

        $this->assertSame($department->id, $calendar->fresh()->department_id);
    }

    public function test_a_plain_member_cannot_create_departments(): void
    {
        $owner = $this->makeOwner();
        $member = User::factory()->create();

        app(WorkspaceService::class)->addMember($owner->currentTenant, $member, 'member');
        $member->forceFill(['current_tenant_id' => $owner->current_tenant_id])->save();

        Livewire::actingAs($member->fresh())
            ->test('pages::departments')
            ->call('create')
            ->assertForbidden();
    }

    public function test_the_dashboard_can_be_filtered_to_one_department(): void
    {
        $user = $this->makeOwner();
        $tenantId = $user->current_tenant_id;

        $department = Department::factory()->create(['tenant_id' => $tenantId, 'name' => 'Releases team']);

        $inside = Calendar::factory()->create([
            'tenant_id' => $tenantId, 'owner_id' => $user->id, 'department_id' => $department->id,
        ]);
        $outside = Calendar::factory()->create([
            'tenant_id' => $tenantId, 'owner_id' => $user->id, 'department_id' => null,
        ]);

        $day = CarbonImmutable::now('Asia/Phnom_Penh')->startOfMonth()->addDays(2);

        Event::factory()->inCalendar($inside, $user)->create([
            'title' => 'Ship v2',
            'starts_at' => $day->setTime(9, 0)->utc(),
            'ends_at' => $day->setTime(10, 0)->utc(),
        ]);
        Event::factory()->inCalendar($outside, $user)->create([
            'title' => 'Dentist',
            'starts_at' => $day->setTime(11, 0)->utc(),
            'ends_at' => $day->setTime(12, 0)->utc(),
        ]);

        $url = '/dashboard?department='.$department->id.'&day='.$day->format('Y-m-d');

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertSee('Ship v2')
            ->assertDontSee('Dentist');
    }

    public function test_a_department_does_not_leak_private_calendars_of_others(): void
    {
        $owner = $this->makeOwner();
        $teammate = User::factory()->create();
        app(WorkspaceService::class)->addMember($owner->currentTenant, $teammate, 'member');

        $department = Department::factory()->create([
            'tenant_id' => $owner->current_tenant_id,
            'name' => 'Shared department',
        ]);

        $secret = Calendar::factory()->create([
            'tenant_id' => $owner->current_tenant_id,
            'owner_id' => $teammate->id,
            'department_id' => $department->id,
            'name' => 'Teammate private plans',
            'visibility' => 'private',
        ]);

        $this->actingAs($owner)
            ->get('/departments')
            ->assertOk()
            ->assertSee('Shared department')
            ->assertDontSee($secret->name);
    }

    public function test_deleting_a_department_leaves_its_calendars_ungrouped(): void
    {
        $user = $this->makeOwner();
        $department = Department::factory()->create(['tenant_id' => $user->current_tenant_id]);
        $calendar = $user->calendars()->firstOrFail();
        $calendar->update(['department_id' => $department->id]);

        Livewire::actingAs($user)
            ->test('pages::departments')
            ->call('delete', $department->id);

        $this->assertSoftDeleted($department);
        $this->assertNull($calendar->fresh()->department_id);
    }
}
