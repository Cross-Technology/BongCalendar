<?php

namespace Tests\Feature;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        app(WorkspaceService::class)->create($user, 'Acme', 'Asia/Phnom_Penh');

        return $user->refresh();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_dashboard_renders_this_month_with_its_events(): void
    {
        $user = $this->makeUser();
        $calendar = $user->calendars()->firstOrFail();

        $event = Event::factory()->inCalendar($calendar, $user)->create([
            'title' => 'Sprint planning',
            'starts_at' => CarbonImmutable::now('UTC')->startOfMonth()->addDays(3)->setTime(2, 0),
            'ends_at' => CarbonImmutable::now('UTC')->startOfMonth()->addDays(3)->setTime(3, 0),
        ]);

        // The month grid shows dots only; titles live in the agenda panel for the
        // selected day, so ask for the day the event falls on.
        $day = $event->starts_at->setTimezone('Asia/Phnom_Penh')->format('Y-m-d');

        $this->actingAs($user)
            ->get('/dashboard?day='.$day)
            ->assertOk()
            ->assertSee('Sprint planning')
            ->assertSee(CarbonImmutable::now('Asia/Phnom_Penh')->format('F Y'));
    }

    public function test_creating_an_event_through_the_modal_persists_it(): void
    {
        $user = $this->makeUser();
        $calendar = $user->calendars()->firstOrFail();

        Livewire::actingAs($user)
            ->test('pages::dashboard')
            ->call('createEvent', '2026-09-10')
            ->assertSet('showModal', true)
            ->set('form_title', 'Design review')
            ->set('form_calendar_id', $calendar->id)
            ->set('form_starts_at', '2026-09-10T09:00')
            ->set('form_ends_at', '2026-09-10T10:00')
            ->set('form_invitees', 'guest@example.com, not-an-email')
            ->call('saveEvent')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $event = Event::where('title', 'Design review')->firstOrFail();

        // 09:00 in Phnom Penh (UTC+7) is 02:00 UTC.
        $this->assertSame('2026-09-10 02:00:00', $event->starts_at->utc()->toDateTimeString());
        // Malformed addresses are dropped rather than failing the save.
        $this->assertSame(['guest@example.com'], $event->invitations()->pluck('email')->all());
    }

    public function test_the_month_navigation_moves_the_grid(): void
    {
        $user = $this->makeUser();

        Livewire::actingAs($user)
            ->test('pages::dashboard', ['month' => '2026-09'])
            ->call('goToMonth', 1)
            ->assertSet('month', '2026-10')
            ->call('goToMonth', -2)
            ->assertSet('month', '2026-08');
    }

    public function test_a_user_cannot_create_an_event_on_a_read_only_calendar(): void
    {
        $owner = $this->makeUser();
        $tenant = $owner->currentTenant;
        $calendar = $owner->calendars()->firstOrFail();

        $viewer = User::factory()->create();
        app(WorkspaceService::class)->addMember($tenant, $viewer);
        $calendar->shares()->create(['user_id' => $viewer->id, 'permission' => 'view']);
        $viewer->refresh();

        Livewire::actingAs($viewer)
            ->test('pages::dashboard')
            ->call('createEvent')
            ->set('form_title', 'Not allowed')
            ->set('form_calendar_id', $calendar->id)
            ->set('form_starts_at', '2026-09-10T09:00')
            ->set('form_ends_at', '2026-09-10T10:00')
            ->call('saveEvent')
            ->assertForbidden();
    }

    public function test_calendars_from_another_workspace_are_not_shown(): void
    {
        $user = $this->makeUser();

        $stranger = User::factory()->create();
        $strangerTenant = app(WorkspaceService::class)->create($stranger, 'Foreign Co');
        Calendar::factory()->shared()->create([
            'tenant_id' => $strangerTenant->id,
            'owner_id' => $stranger->id,
            'name' => 'Foreign Calendar',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Foreign Calendar');
    }
}
