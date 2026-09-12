<?php

namespace App\Http\Requests\Api;

use App\Models\Note;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NoteRequest extends FormRequest
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
            // The body carries the note; a title is a convenience on top of it.
            'title' => ['nullable', 'string', 'max:255'],
            // Markup now, so the ceiling is raised accordingly.
            'body' => [$required, 'string', 'max:200000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'visibility' => ['nullable', Rule::in(Note::VISIBILITIES)],
            'is_pinned' => ['nullable', 'boolean'],
        ];
    }
}
