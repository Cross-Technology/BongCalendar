<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RichTextService;
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
            'body' => '<div>'.e(fake()->paragraph()).'</div>',
            'color' => fake()->randomElement(Note::COLORS),
            'visibility' => 'tenant',
            'is_pinned' => false,
        ];
    }

    /**
     * body_text is derived rather than declared, so a test that overrides the
     * body still gets a matching plain-text rendering — otherwise search would
     * silently look at the wrong words.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Note $note) {
            $note->body_text ??= app(RichTextService::class)->toText((string) $note->body);
        });
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
