<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'tenant_id' => Tenant::factory(),
            'department_id' => Department::factory(),
            'author_id' => User::factory(),
            'report_date' => now()->toDateString(),
            'body' => '<div>'.e($text).'</div>',
            'body_text' => $text,
        ];
    }

    public function on(string $date): static
    {
        return $this->state(['report_date' => $date]);
    }
}
