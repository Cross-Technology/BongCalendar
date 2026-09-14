<?php

namespace App\Http\Requests\Api;

use App\Models\Task;
use App\Services\TaskRecurrenceService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->require()->id;

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'position' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],

            // Everything referenced must live in the caller's own workspace.
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],

            /*
             * A task can be shared between departments and picked up by more
             * than one person. The singular keys above stay for clients that
             * only ever send one, and read as a set of one.
             */
            'department_ids' => ['nullable', 'array', 'max:20'],
            'department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'assignee_ids' => ['nullable', 'array', 'max:20'],
            'assignee_ids.*' => [
                'integer',
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            // Repeating: one real task per occurrence, created together.
            'repeat' => ['nullable', 'array'],
            'repeat.frequency' => ['required_with:repeat', Rule::in(TaskRecurrenceService::FREQUENCIES)],
            'repeat.interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'repeat.days_of_week' => ['nullable', 'array', 'max:7'],
            'repeat.days_of_week.*' => ['integer', 'between:1,7'],
            // `exclude_without` keeps these out of validation entirely when no
            // repeat was asked for — otherwise `required_without` fires on an
            // ordinary one-off task and refuses it.
            'repeat.until' => ['exclude_without:repeat', 'nullable', 'date', 'required_without:repeat.count'],
            'repeat.count' => [
                'exclude_without:repeat', 'nullable', 'integer', 'min:1',
                'max:'.TaskRecurrenceService::MAX_OCCURRENCES,
                'required_without:repeat.until',
            ],

            'parent_task_id' => [
                'nullable', 'integer',
                Rule::exists('tasks', 'id')
                    ->where('tenant_id', $tenantId)
                    // Only one level of nesting: a subtask cannot own subtasks.
                    ->whereNull('parent_task_id')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'That person is not a member of this workspace.',
            'assignee_ids.*.exists' => 'One of those people is not a member of this workspace.',
            'department_ids.*.exists' => 'One of those departments is not in this workspace.',
            'parent_task_id.exists' => 'A subtask can only hang off a top-level task in this workspace.',
        ];
    }
}
