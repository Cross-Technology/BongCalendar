<?php

namespace App\Providers;

use App\Models\Calendar;
use App\Models\Department;
use App\Models\Event;
use App\Models\Note;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Tenant;
use App\Policies\CalendarPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EventPolicy;
use App\Policies\NotePolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskTemplatePolicy;
use App\Policies\TenantPolicy;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Calendar::class, CalendarPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(TaskTemplate::class, TaskTemplatePolicy::class);

        /*
         * Workspaces read by slug in URLs, but clients that hold an id should
         * not have to look the slug up first. Accepting either matches the
         * X-Tenant header, which has always taken both.
         */
        Route::bind('tenant', fn ($value) => Tenant::where('slug', $value)
            ->orWhere('id', $value)
            ->firstOrFail());
    }
}
