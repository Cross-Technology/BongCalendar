<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Database\Seeder;

/**
 * Seeds the bare minimum needed to sign in: one account and one empty
 * workspace. No demo calendars, events, tasks or teammates — the app is meant
 * to be filled with real data.
 */
class DatabaseSeeder extends Seeder
{
    public function run(WorkspaceService $workspaces): void
    {
        $email = (string) env('SEED_USER_EMAIL', 'rady@example.com');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('SEED_USER_NAME', 'Rady'),
                'password' => (string) env('SEED_USER_PASSWORD', 'password'),
                'timezone' => (string) env('SEED_USER_TIMEZONE', 'Asia/Phnom_Penh'),
                'email_verified_at' => now(),
            ]
        );

        // WorkspaceService gives the owner a default calendar so the dashboard
        // has somewhere to put a first event.
        if ($user->tenants()->doesntExist()) {
            $workspaces->create($user, (string) env('SEED_WORKSPACE_NAME', 'My Workspace'), $user->timezone);
        }

        $this->command?->info("Seeded: {$user->email} / password");
    }
}
