<?php

namespace Tests\Feature\Api;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Asia/Phnom_Penh']);
        $this->tenant = app(WorkspaceService::class)->create($this->user, 'Acme', 'Asia/Phnom_Penh');
        $this->user->refresh();
        $this->calendar = $this->user->calendars()->firstOrFail();
    }

    protected function asUser(?User $user = null): static
    {
        return $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user ?? $this->user));
    }

    public function test_it_creates_an_event_and_stores_the_time_in_utc(): void
    {
        $response = $this->asUser()->postJson('/api/v1/events', [
            'calendar_id' => $this->calendar->id,
            'title' => 'Sprint planning',
            'starts_at' => '2026-09-01 09:00',
            'ends_at' => '2026-09-01 10:00',
            'timezone' => 'Asia/Phnom_Penh',
            'invitees' => ['guest@example.com'],
        ])->assertCreated();

        $event = Event::findOrFail($response->json('data.id'));

        // Phnom Penh is UTC+7, so 09:00 local is 02:00 UTC.
        $this->assertSame('2026-09-01 02:00:00', $event->starts_at->utc()->toDateTimeString());
        $this->assertSame('Asia/Phnom_Penh', $event->timezone);
        $this->assertDatabaseHas('event_invitations', [
            'event_id' => $event->id,
            'email' => 'guest@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_it_rejects_an_end_before_the_start(): void
    {
        $this->asUser()->postJson('/api/v1/events', [
            'calendar_id' => $this->calendar->id,
            'title' => 'Backwards',
            'starts_at' => '2026-09-01 10:00',
            'ends_at' => '2026-09-01 09:00',
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_it_returns_events_overlapping_the_window_including_multi_day_ones(): void
    {
        $spanning = Event::factory()->inCalendar($this->calendar)->create([
            'title' => 'Conference',
            'starts_at' => CarbonImmutable::parse('2026-08-28 00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-03 00:00', 'UTC'),
        ]);

        $outside = Event::factory()->inCalendar($this->calendar)->create([
            'title' => 'Far future',
            'starts_at' => CarbonImmutable::parse('2026-12-01 09:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 10:00', 'UTC'),
        ]);

        $ids = $this->asUser()->getJson('/api/v1/events?'.http_build_query([
            'from' => '2026-09-01 00:00',
            'to' => '2026-09-30 23:59',
        ]))->assertOk()->json('data.*.id');

        $this->assertContains($spanning->id, $ids, 'an event starting before the window but ending inside it should be returned');
        $this->assertNotContains($outside->id, $ids);
    }

    public function test_a_user_without_write_access_cannot_add_events_to_a_shared_calendar(): void
    {
        $viewer = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $viewer);
        $this->calendar->shares()->create(['user_id' => $viewer->id, 'permission' => 'view']);

        $this->asUser($viewer)->postJson('/api/v1/events', [
            'calendar_id' => $this->calendar->id,
            'title' => 'Not allowed',
            'starts_at' => '2026-09-01 09:00',
            'ends_at' => '2026-09-01 10:00',
        ])->assertForbidden();
    }

    public function test_an_editor_can_update_an_event_they_did_not_create(): void
    {
        $editor = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $editor);
        $this->calendar->shares()->create(['user_id' => $editor->id, 'permission' => 'edit']);

        $event = Event::factory()->inCalendar($this->calendar, $this->user)->create();

        $this->asUser($editor)
            ->patchJson("/api/v1/events/{$event->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_an_unrelated_user_cannot_read_an_event(): void
    {
        $event = Event::factory()->inCalendar($this->calendar, $this->user)->create();

        $stranger = User::factory()->create();
        app(WorkspaceService::class)->create($stranger, 'Stranger Co');
        $stranger->refresh();

        $this->asUser($stranger)->getJson("/api/v1/events/{$event->id}")->assertForbidden();
    }

    public function test_an_invitee_can_respond_to_an_invitation(): void
    {
        $guest = User::factory()->create();
        app(WorkspaceService::class)->addMember($this->tenant, $guest);

        $event = Event::factory()->inCalendar($this->calendar, $this->user)->create();
        $event->invitations()->create(['user_id' => $guest->id, 'email' => $guest->email, 'status' => 'pending']);

        $this->asUser($guest)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/events/{$event->id}/respond", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('event_invitations', [
            'event_id' => $event->id,
            'user_id' => $guest->id,
            'status' => 'accepted',
        ]);
    }
}
