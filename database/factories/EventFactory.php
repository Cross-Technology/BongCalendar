<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::instance(fake()->dateTimeBetween('-2 weeks', '+6 weeks'))
            ->setTime(fake()->numberBetween(8, 17), fake()->randomElement([0, 30]));

        return [
            'tenant_id' => Tenant::factory(),
            'calendar_id' => Calendar::factory(),
            'created_by' => User::factory(),
            'title' => fake()->randomElement([
                'Standup', 'Design review', 'Customer call', '1:1', 'Sprint planning',
                'Lunch', 'Retro', 'Interview', 'Demo', 'Deep work',
            ]),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->randomElement(['Meeting room A', 'Zoom', 'Office', 'Phnom Penh HQ']),
            'color' => null,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'timezone' => 'UTC',
            'all_day' => false,
            'status' => 'confirmed',
        ];
    }

    public function allDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'all_day' => true,
            'starts_at' => CarbonImmutable::parse($attributes['starts_at'])->startOfDay(),
            'ends_at' => CarbonImmutable::parse($attributes['starts_at'])->endOfDay(),
        ]);
    }

    /** Keep tenant/calendar/creator consistent instead of spawning new ones. */
    public function inCalendar(Calendar $calendar, ?User $creator = null): static
    {
        return $this->state(fn () => [
            'tenant_id' => $calendar->tenant_id,
            'calendar_id' => $calendar->id,
            'created_by' => $creator?->id ?? $calendar->owner_id,
        ]);
    }
}
