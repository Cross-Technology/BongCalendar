<?php

namespace App\Http\Requests\Api;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarRequest extends FormRequest
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

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['nullable', 'timezone'],
            'visibility' => ['nullable', Rule::in(['private', 'tenant', 'public'])],
            // null moves the calendar out of every department; anything else
            // must name a department in the caller's own workspace.
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')
                    ->where('tenant_id', app(TenantContext::class)->require()->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
