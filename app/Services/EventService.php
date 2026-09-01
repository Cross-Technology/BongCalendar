<?php

namespace App\Services;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Calendar $calendar, User $creator, array $data): Event
    {
        return DB::transaction(function () use ($calendar, $creator, $data) {
            $timezone = $data['timezone'] ?? $calendar->timezone ?? 'UTC';

            $event = $calendar->events()->create([
                'tenant_id' => $calendar->tenant_id,
                'created_by' => $creator->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'color' => $data['color'] ?? null,
                'starts_at' => $this->toUtc($data['starts_at'], $timezone),
                'ends_at' => $this->toUtc($data['ends_at'], $timezone),
                'timezone' => $timezone,
                'all_day' => (bool) ($data['all_day'] ?? false),
                'status' => $data['status'] ?? 'confirmed',
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
                'recurrence_until' => isset($data['recurrence_until'])
                    ? $this->toUtc($data['recurrence_until'], $timezone)
                    : null,
            ]);

            $this->syncInvitations($event, $data['invitees'] ?? []);
            $this->syncReminders($event, $creator, $data['reminders'] ?? []);

            return $event->load(['calendar', 'creator', 'invitations', 'reminders']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data): Event
    {
        return DB::transaction(function () use ($event, $data) {
            $timezone = $data['timezone'] ?? $event->timezone;

            $attributes = array_filter([
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'color' => $data['color'] ?? null,
                'status' => $data['status'] ?? null,
                'recurrence_rule' => $data['recurrence_rule'] ?? null,
            ], fn ($value) => $value !== null);

            // Nullable fields have to bypass the filter above to be clearable.
            foreach (['description', 'location', 'color', 'recurrence_rule'] as $nullable) {
                if (array_key_exists($nullable, $data)) {
                    $attributes[$nullable] = $data[$nullable];
                }
            }

            if (isset($data['starts_at'])) {
                $attributes['starts_at'] = $this->toUtc($data['starts_at'], $timezone);
            }

            if (isset($data['ends_at'])) {
                $attributes['ends_at'] = $this->toUtc($data['ends_at'], $timezone);
            }

            if (array_key_exists('all_day', $data)) {
                $attributes['all_day'] = (bool) $data['all_day'];
            }

            if (array_key_exists('calendar_id', $data)) {
                $attributes['calendar_id'] = $data['calendar_id'];
            }

            $attributes['timezone'] = $timezone;

            $event->update($attributes);

            if (array_key_exists('invitees', $data)) {
                $this->syncInvitations($event, $data['invitees']);
            }

            return $event->fresh(['calendar', 'creator', 'invitations', 'reminders']);
        });
    }

    /**
     * Events visible to a user in a window, ordered for calendar rendering.
     *
     * @param  array<int>|null  $calendarIds  Restrict to these calendars.
     * @return Collection<int, Event>
     */
    public function inRange(
        User $user,
        int $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $calendarIds = null,
    ): Collection {
        $visible = Calendar::visibleTo($user, $tenantId)->pluck('id')->all();

        if ($calendarIds !== null) {
            $visible = array_values(array_intersect($visible, $calendarIds));
        }

        if ($visible === []) {
            return collect();
        }

        return Event::query()
            ->forCalendars($visible)
            ->overlapping($from, $to)
            ->where('tenant_id', $tenantId)
            ->with(['calendar:id,name,color', 'creator:id,name'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  array<int, string>  $emails
     */
    public function syncInvitations(Event $event, array $emails): void
    {
        $emails = collect($emails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values();

        $event->invitations()->whereNotIn('email', $emails)->delete();

        $users = User::whereIn('email', $emails)->pluck('id', 'email');

        foreach ($emails as $email) {
            $event->invitations()->firstOrCreate(
                ['email' => $email],
                ['user_id' => $users[$email] ?? null, 'status' => 'pending'],
            );
        }
    }

    /**
     * @param  array<int, int>  $minutes
     */
    public function syncReminders(Event $event, User $user, array $minutes): void
    {
        foreach (array_unique($minutes) as $minutesBefore) {
            $event->reminders()->firstOrCreate([
                'user_id' => $user->id,
                'minutes_before' => (int) $minutesBefore,
                'channel' => 'email',
            ]);
        }
    }

    /**
     * Wall-clock time in the event's zone is what the user typed; the column
     * stores UTC so cross-timezone queries compare correctly.
     */
    protected function toUtc(mixed $value, string $timezone): CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }

        return CarbonImmutable::parse($value, $timezone)->setTimezone('UTC');
    }
}
