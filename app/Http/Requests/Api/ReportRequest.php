<?php

namespace App\Http\Requests\Api;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->require()->id;

        return [
            // Fixed on create; a report is for a department and a day, and
            // moving it to another day would overwrite that day's record.
            'department_id' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                Rule::exists('departments', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'report_date' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                'date_format:Y-m-d',
            ],
            'body' => ['required', 'string', 'max:200000'],
        ];
    }
}
