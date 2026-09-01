<?php

namespace App\Http\Requests\Api;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'calendar_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:calendars,id'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after_or_equal:starts_at'],
            'timezone' => ['nullable', 'timezone'],
            'all_day' => ['boolean'],
            'status' => ['nullable', Rule::in(Event::STATUSES)],
            'recurrence_rule' => ['nullable', 'string', 'max:255'],
            'recurrence_until' => ['nullable', 'date', 'after:starts_at'],
            'invitees' => ['array'],
            'invitees.*' => ['email'],
            'reminders' => ['array'],
            'reminders.*' => ['integer', 'min:0', 'max:40320'],
        ];
    }
}
