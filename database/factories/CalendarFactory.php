<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'owner_id' => User::factory(),
            'name' => fake()->randomElement(['Work', 'Personal', 'Team', 'Travel', 'Holidays']).' '.fake()->numberBetween(1, 99),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->randomElement(['#2563eb', '#16a34a', '#db2777', '#f59e0b', '#7c3aed']),
            'timezone' => 'UTC',
            'visibility' => 'private',
            'is_default' => false,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['visibility' => 'tenant']);
    }
}
