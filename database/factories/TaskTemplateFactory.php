<?php

namespace Database\Factories;

use App\Models\TaskTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'name' => $title,
            'title' => $title,
            'priority' => 'medium',
            'tags' => [],
            'checklist' => [],
        ];
    }
}
