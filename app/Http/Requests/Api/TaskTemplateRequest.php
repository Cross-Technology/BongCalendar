<?php

namespace App\Http\Requests\Api;

use App\Models\Task;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskTemplateRequest extends FormRequest
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
            // The task this stamps out always needs a title; the template's own
            // name falls back to it.
            'title' => [$required, 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'checklist' => ['nullable', 'array', 'max:50'],
            'checklist.*' => ['string', 'max:255'],
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
        ];
    }
}
