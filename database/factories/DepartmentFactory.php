<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->randomElement(['Sales', 'Operations', 'Engineering', 'Errands', 'Marketing', 'Support'])
                .' '.fake()->numberBetween(1, 99),
            'description' => fake()->optional()->sentence(),
            'color' => fake()->randomElement(['#6f5cf0', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444']),
            'position' => 0,
        ];
    }
}
