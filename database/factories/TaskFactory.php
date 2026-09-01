<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'status' => 'todo',
            'priority' => fake()->randomElement(Task::PRIORITIES),
            'due_date' => null,
            'position' => 0,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
        ]);
    }

    public function dueOn(\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['due_date' => $when]);
    }
}
