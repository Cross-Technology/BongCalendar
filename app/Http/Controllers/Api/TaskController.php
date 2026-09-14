<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskRecurrenceService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(
        protected TenantContext $tenants,
        protected TaskRecurrenceService $recurrence,
    ) {}

    /**
     * Filters mirror the mobile app's TaskFilters: status[], priority[],
     * department, assignee, tag, and a due window.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenant = $this->tenants->require();
        $tz = $request->user()->timezone ?: $tenant->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        $tasks = Task::forTenant($tenant->id)
            ->when($request->boolean('roots_only', true), fn ($q) => $q->roots())
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', (array) $request->input('status')))
            ->when($request->filled('priority'), fn ($q) => $q->whereIn('priority', (array) $request->input('priority')))
            // Both take one id or a list: a task can sit in several departments
            // and be on several people, so a filter matches if any of them do.
            ->when($request->filled('department'), fn ($q) => $q->inDepartments(
                array_map('intval', (array) $request->input('department'))
            ))
            ->when($request->filled('assignee'), fn ($q) => $q->assignedTo(
                array_map('intval', (array) $request->input('assignee'))
            ))
            ->when($request->filled('tag'), fn ($q) => $q->whereJsonContains('tags', $request->string('tag')->value()))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->filled('due'), function ($q) use ($request, $now) {
                return match ($request->string('due')->value()) {
                    'today' => $q->whereBetween('due_date', [$now->startOfDay()->utc(), $now->endOfDay()->utc()]),
                    'week' => $q->whereBetween('due_date', [$now->startOfDay()->utc(), $now->addWeek()->endOfDay()->utc()]),
                    'overdue' => $q->where('due_date', '<', $now->utc())->open(),
                    'none' => $q->whereNull('due_date'),
                    default => $q,
                };
            })
            ->with([
                'assignee:id,name,email', 'department:id,tenant_id,name,color,slug',
                'assignees:id,name,email', 'departments:id,tenant_id,name,color,slug',
            ])
            // Opt-in so the common listing stays a single cheap query, while a
            // client that needs checklists avoids one request per task.
            ->when($request->boolean('with_checklist'), fn ($q) => $q->with('checklist'))
            ->withCount(['subtasks', 'checklist'])
            ->boardOrder()
            ->get();

        return TaskResource::collection($tasks);
    }

    public function store(TaskRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [Task::class, $tenant->id]);

        $data = $request->validated();
        $repeat = $data['repeat'] ?? null;
        $owners = $this->ownerSets($data);
        unset($data['repeat'], $data['department_ids'], $data['assignee_ids']);

        $attributes = $data + [
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()->id,
            'status' => $request->input('status', 'todo'),
            'priority' => $request->input('priority', 'medium'),
        ];

        if (! $repeat) {
            return response()->json([
                'data' => new TaskResource($this->createOne($attributes, owners: $owners)),
            ], 201);
        }

        $tz = $request->user()->timezone ?: $tenant->timezone ?: 'UTC';

        /*
         * A repeat walks the day the work is scheduled for. That is the start
         * date when one was given — "every day, starting Monday" — and the due
         * date otherwise. When both exist the gap between them is kept, so a
         * task that starts Monday and is due Friday repeats as exactly that.
         */
        $anchorField = isset($attributes['start_date']) ? 'start_date' : 'due_date';
        $anchorValue = $attributes[$anchorField] ?? null;

        $start = $anchorValue
            ? CarbonImmutable::parse($anchorValue)->setTimezone($tz)
            : CarbonImmutable::now($tz)->setTime(9, 0);

        $gap = ($anchorField === 'start_date' && isset($attributes['due_date']))
            ? CarbonImmutable::parse($attributes['start_date'])
                ->diffInSeconds(CarbonImmutable::parse($attributes['due_date']))
            : null;

        try {
            $dates = $this->recurrence->dates($start, $repeat, $tz);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $seriesId = (string) Str::uuid();

        $tasks = DB::transaction(fn () => collect($dates)->map(
            fn (CarbonImmutable $date) => $this->createOne(
                $attributes + ['series_id' => $seriesId],
                $this->occurrenceDates($date, $anchorField, $gap),
                $owners,
            )
        ));

        return response()->json([
            'data' => TaskResource::collection($tasks),
            'meta' => ['series_id' => $seriesId, 'created' => $tasks->count()],
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $dates  Date columns to override for this occurrence.
     * @param  array{departments: ?array<int, int>, assignees: ?array<int, int>}  $owners
     */
    protected function createOne(array $attributes, array $dates = [], array $owners = []): Task
    {
        $attributes = array_merge($attributes, $dates);

        $task = Task::create($attributes);

        // Creating a task straight into `done` should still be stamped.
        if ($task->isDone()) {
            $task->forceFill(['completed_at' => now()])->save();
        }

        $this->applyOwners($task, $owners);

        return $task;
    }

    /**
     * The department and assignee sets a request asked for, or null for each
     * one it said nothing about — which must not be read as "clear it".
     *
     * A lone `department_id` / `assignee_id` counts as a set of one, so a
     * client that only ever sends one keeps working unchanged.
     *
     * @param  array<string, mixed>  $data
     * @return array{departments: ?array<int, int>, assignees: ?array<int, int>}
     */
    protected function ownerSets(array $data): array
    {
        $set = function (?string $listKey, ?string $singleKey) use ($data): ?array {
            if (array_key_exists($listKey, $data)) {
                return array_map('intval', (array) ($data[$listKey] ?? []));
            }

            if (array_key_exists($singleKey, $data)) {
                return array_filter([$data[$singleKey]]);
            }

            return null;
        };

        return [
            'departments' => $set('department_ids', 'department_id'),
            'assignees' => $set('assignee_ids', 'assignee_id'),
        ];
    }

    /**
     * People first: handing an unfiled task to someone files it under their
     * department, and an explicit set of departments should then win.
     *
     * @param  array{departments?: ?array<int, int>, assignees?: ?array<int, int>}  $owners
     */
    protected function applyOwners(Task $task, array $owners): void
    {
        if (($owners['assignees'] ?? null) !== null) {
            $task->syncAssignees($owners['assignees']);
        }

        if (($owners['departments'] ?? null) !== null) {
            $task->syncDepartments($owners['departments']);
        }
    }

    /**
     * The date columns for one occurrence.
     *
     * @return array<string, mixed>
     */
    protected function occurrenceDates(CarbonImmutable $date, string $anchorField, ?int $gap): array
    {
        $dates = [$anchorField => $date->utc()];

        if ($gap !== null) {
            $dates['due_date'] = $date->addSeconds($gap)->utc();
        }

        return $dates;
    }

    /** How many tasks a rule would create, so the UI can say so before saving. */
    public function previewRepeat(Request $request): JsonResponse
    {
        $tenant = $this->tenants->require();
        $tz = $request->user()->timezone ?: $tenant->timezone ?: 'UTC';

        $rule = $request->validate([
            'frequency' => ['required', Rule::in(TaskRecurrenceService::FREQUENCIES)],
            'interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'until' => ['nullable', 'date'],
            'count' => ['nullable', 'integer', 'min:1', 'max:'.TaskRecurrenceService::MAX_OCCURRENCES],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
        ]);

        // Preview counts from the same anchor the save would use.
        $anchor = $rule['start_date'] ?? $rule['due_date'] ?? null;

        $start = $anchor
            ? CarbonImmutable::parse($anchor)->setTimezone($tz)
            : CarbonImmutable::now($tz)->setTime(9, 0);

        try {
            $dates = $this->recurrence->dates($start, $rule, $tz);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'count' => count($dates),
            'first' => $dates[0] ?? null,
            'last' => end($dates) ?: null,
        ]]);
    }

    /** Deletes every occurrence of a repeating task. */
    public function destroySeries(Request $request, string $series): JsonResponse
    {
        $tasks = Task::forTenant($this->tenants->require()->id)->inSeries($series)->get();

        abort_if($tasks->isEmpty(), 404);

        // One check for the set: they were created together by one person.
        $this->authorize('delete', $tasks->first());

        $deleted = 0;

        foreach ($tasks as $task) {
            $task->delete();
            $deleted++;
        }

        return response()->json(['message' => "Deleted {$deleted} tasks.", 'meta' => ['deleted' => $deleted]]);
    }

    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'data' => new TaskResource(
                $task->load([
                    'assignee:id,name,email', 'creator:id,name,email', 'department',
                    'assignees:id,name,email', 'departments',
                    'subtasks', 'checklist',
                ])
            ),
        ]);
    }

    public function update(TaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validated();

        // Status carries a side effect, so route it through the model rather
        // than letting a bare update leave completed_at stale.
        $status = $data['status'] ?? null;
        $owners = $this->ownerSets($data);
        unset($data['status'], $data['department_ids'], $data['assignee_ids'], $data['department_id'], $data['assignee_id']);

        $task->update($data);

        if ($status !== null && $status !== $task->status) {
            $task->setStatus($status);
        }

        $this->applyOwners($task, $owners);

        return response()->json([
            'data' => new TaskResource(
                $task->fresh()->load(['assignee', 'department', 'assignees', 'departments'])
            ),
        ]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /** The board's drag-and-drop and the mobile completion button land here. */
    public function status(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'position' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ]);

        $task->setStatus($data['status']);

        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $task->update(['position' => $data['position']]);
        }

        return response()->json(['data' => new TaskResource($task->fresh())]);
    }
}
