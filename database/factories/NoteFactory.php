<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'author_id' => User::factory(),
            'title' => fake()->optional()->sentence(4),
            'body' => fake()->paragraph(),
            'color' => fake()->randomElement(Note::COLORS),
            'visibility' => 'tenant',
            'is_pinned' => false,
        ];
    }

    public function private(): static
    {
        return $this->state(['visibility' => 'private']);
    }

    public function pinned(): static
    {
        return $this->state(['is_pinned' => true]);
    }
}
