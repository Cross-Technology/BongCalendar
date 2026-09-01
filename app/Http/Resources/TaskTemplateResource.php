<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskTemplateResource extends JsonResource
{
    /**
     * Field names mirror the mobile TaskTemplate type.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'department_id' => $this->department_id,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'note' => $this->note,
            'priority' => $this->priority,
            'tags' => $this->tags ?? [],
            'checklist' => $this->checklist ?? [],
            'last_used_at' => $this->last_used_at,
            'use_count' => $this->use_count,
            'created_by' => $this->created_by,
            'can_edit' => $user
                ? ($this->created_by === $user->id || $user->isTenantAdmin($this->tenant_id))
                : false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
