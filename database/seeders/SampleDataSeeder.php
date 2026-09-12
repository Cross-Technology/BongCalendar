<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\ReportService;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fills a workspace with a worked example: four departments with people in
 * them, a day of tasks carrying real checklists and priorities, and a couple
 * of reports already written.
 *
 * Deliberately NOT part of DatabaseSeeder — the app is meant to be filled with
 * real data, and this is only for seeing how the pieces fit:
 *
 *     php artisan db:seed --class=SampleDataSeeder
 *
 * Safe to re-run: it matches on names and tops up rather than duplicating.
 */
class SampleDataSeeder extends Seeder
{
    public function run(
        WorkspaceService $workspaces,
        DepartmentService $departmentService,
        ReportService $reports,
    ): void {
        $owner = User::where('email', (string) env('SEED_USER_EMAIL', 'rady@example.com'))->first()
            ?? User::orderBy('id')->first();

        if (! $owner) {
            $this->command?->error('No users yet — run `php artisan db:seed` first.');

            return;
        }

        $tenant = $owner->currentTenant ?? $owner->tenants()->first();

        if (! $tenant) {
            $this->command?->error("{$owner->email} has no workspace to fill.");

            return;
        }

        $timezone = $tenant->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);

        $people = $this->seedPeople($tenant, $workspaces);
        $departments = $this->seedDepartments($tenant, $departmentService, $owner, $people);

        $this->seedTasks($tenant, $departments, $people, $owner, $today);
        $this->seedReports($tenant, $departments, $people, $reports, $today->subDay());

        $this->command?->info("Sample data added to {$tenant->name}.");
        $this->command?->info('Sign in as any of: '.collect($people)->pluck('email')->implode(', ').' (password: password)');
    }

    /** @return array<string, User> */
    protected function seedPeople(Tenant $tenant, WorkspaceService $workspaces): array
    {
        $definitions = [
            'sophea' => ['Sophea Chan', 'sophea@example.com', 'admin'],
            'dara' => ['Dara Kim', 'dara@example.com', 'member'],
            'chantha' => ['Chantha Sok', 'chantha@example.com', 'member'],
        ];

        $people = [];

        foreach ($definitions as $key => [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'password',
                    'timezone' => $tenant->timezone ?: 'UTC',
                    'email_verified_at' => now(),
                ]
            );

            $workspaces->addMember($tenant, $user, $role);

            $people[$key] = $user->refresh();
        }

        return $people;
    }

    /**
     * @param  array<string, User>  $people
     * @return array<string, Department>
     */
    protected function seedDepartments(Tenant $tenant, DepartmentService $service, User $owner, array $people): array
    {
        $definitions = [
            'operations' => ['Operations', '#0ea5e9', 'Opening up, stock, and the day-to-day running of the shop.', ['sophea' => 'lead', 'chantha' => 'member']],
            'sales' => ['Sales', '#10b981', 'Leads, quotes, and following customers up.', ['dara' => 'lead']],
            'kitchen' => ['Kitchen', '#f59e0b', 'Prep, service, and food safety checks.', ['chantha' => 'lead']],
            'admin' => ['Admin', '#6f5cf0', 'Invoices, payroll, and the paperwork nobody enjoys.', ['sophea' => 'member']],
        ];

        $departments = [];

        foreach ($definitions as $key => [$name, $color, $description, $members]) {
            $department = Department::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['description' => $description, 'color' => $color]
            );

            // The owner sees every department, so they sit in all of them.
            $service->addMember($department, $owner, 'member');

            foreach ($members as $personKey => $role) {
                $service->addMember($department, $people[$personKey], $role);
            }

            // A header each, so the sample data shows what they are for.
            if (blank($department->report_header)) {
                $department->update([
                    'report_header' => '<h1>{{department}} — Daily Report</h1>'
                        .'<div>{{date}} · prepared by {{author}} · {{workspace}}</div>',
                ]);
            }

            $departments[$key] = $department;
        }

        return $departments;
    }

    /**
     * Each task carries a description, a priority and a checklist part-ticked,
     * so the Reports page has real progress to show rather than empty rows.
     *
     * @param  array<string, Department>  $departments
     * @param  array<string, User>  $people
     */
    protected function seedTasks(Tenant $tenant, array $departments, array $people, User $owner, CarbonImmutable $today): void
    {
        foreach ($this->taskDefinitions() as [$departmentKey, $assigneeKey, $title, $description, $priority, $status, $checklist]) {
            $department = $departments[$departmentKey];

            $task = Task::firstOrNew([
                'tenant_id' => $tenant->id,
                'department_id' => $department->id,
                'title' => $title,
            ]);

            if ($task->exists) {
                continue;
            }

            $task->fill([
                'created_by' => $owner->id,
                'assignee_id' => ($people[$assigneeKey] ?? $owner)->id,
                'description' => $description,
                'priority' => $priority,
                'status' => $status,
                'completed_at' => $status === 'done' ? $today->setTime(16, 0) : null,
                'start_date' => $today->setTime(9, 0)->toIso8601String(),
            ])->save();

            foreach ($checklist as $position => [$itemTitle, $completed]) {
                ChecklistItem::create([
                    'task_id' => $task->id,
                    'title' => $itemTitle,
                    'completed' => $completed,
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: array<int, array{0: string, 1: bool}>}>
     */
    protected function taskDefinitions(): array
    {
        return [
            ['operations', 'sophea', 'Open the shop', 'Everything that has to happen before the doors open at 7am.', 'high', 'in_progress', [
                ['Unlock and disarm the alarm', true],
                ['Count the float', true],
                ['Check the fridge temperatures', false],
            ]],
            ['operations', 'chantha', 'Weekly stock count', 'Count what is on the shelves and flag anything running low.', 'medium', 'todo', [
                ['Dry goods', false],
                ['Drinks', false],
                ['Cleaning supplies', false],
            ]],
            ['operations', 'sophea', 'Fix the back door lock', 'Sticking since Tuesday. Waiting on the locksmith to call back.', 'urgent', 'blocked', []],

            ['sales', 'dara', 'Follow up the Sok Heng quote', 'Sent last Thursday. Ask whether they want the larger order.', 'high', 'done', [
                ['Call the office', true],
                ['Resend the quote', true],
            ]],
            ['sales', 'dara', 'Call three new leads', 'From the market stall sign-up sheet.', 'medium', 'in_progress', [
                ['Phnom Penh Bakery', true],
                ['Riverside Cafe', false],
                ['Toul Kork Grocers', false],
            ]],
            ['sales', 'dara', 'Update the price list', 'Supplier costs went up 4% this month.', 'low', 'todo', []],

            ['kitchen', 'chantha', 'Morning prep', 'Everything chopped and portioned before the lunch rush.', 'high', 'done', [
                ['Vegetables', true],
                ['Sauces', true],
                ['Rice', true],
                ['Label and date everything', true],
            ]],
            ['kitchen', 'chantha', 'Deep clean the fryer', 'Due every Friday. Takes about an hour once it has cooled.', 'medium', 'todo', []],
            ['kitchen', 'chantha', 'Check the supplier delivery', 'Weigh it, check the dates, and sign the docket.', 'high', 'in_progress', [
                ['Check quantities against the docket', true],
                ['Check use-by dates', false],
            ]],

            ['admin', 'sophea', 'Send September invoices', 'Twelve to go out before the end of the week.', 'urgent', 'in_progress', [
                ['Pull the hours from the roster', true],
                ['Draft the invoices', true],
                ['Check them against the quotes', false],
                ['Send and file', false],
            ]],
            ['admin', 'sophea', 'File the tax paperwork', 'Quarterly return. Everything is in the shared folder.', 'high', 'todo', []],
            ['admin', 'sophea', 'Reconcile petty cash', 'Receipts are in the tin behind the counter.', 'low', 'done', [
                ['Total the receipts', true],
                ['Match against the tin', true],
            ]],
        ];
    }

    /**
     * @param  array<string, Department>  $departments
     * @param  array<string, User>  $people
     */
    protected function seedReports(Tenant $tenant, array $departments, array $people, ReportService $reports, CarbonImmutable $day): void
    {
        $written = [
            ['operations', 'sophea', '<div>Opened on time. Float counted and correct.</div>'
                .'<div>The back door lock is still sticking — locksmith is booked for Thursday morning.</div>'
                .'<h1>Stock</h1><ul><li>Drinks running low, ordered more</li><li>Cleaning supplies fine for another week</li></ul>'],
            ['sales', 'dara', '<div>Sok Heng confirmed the larger order — invoice to follow.</div>'
                .'<div>Called four leads from the market stall list:</div>'
                .'<ul><li>Phnom Penh Bakery — wants a quote</li><li>Riverside Cafe — call back Monday</li><li>Two no-answers</li></ul>'],
        ];

        foreach ($written as [$departmentKey, $authorKey, $body]) {
            $reports->write(
                $tenant,
                $departments[$departmentKey],
                $people[$authorKey],
                $day,
                $body,
            );
        }
    }
}
