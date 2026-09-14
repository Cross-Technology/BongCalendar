<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Field names mirror the mobile app's Task type so the client can map
     * straight across.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'department_id' => $this->department_id,
            'parent_task_id' => $this->parent_task_id,
            'series_id' => $this->series_id,
            'title' => $this->title,
            'description' => $this->description,
            'note' => $this->note,
            'status' => $this->status,
            'status_meta' => Task::STATUS_META[$this->status] ?? null,
            'priority' => $this->priority,
            'priority_meta' => Task::PRIORITY_META[$this->priority] ?? null,
            'tags' => $this->tags ?? [],
            'position' => $this->position,
            'start_date' => $this->start_date,
            'due_date' => $this->due_date,
            'completed_at' => $this->completed_at,
            'is_overdue' => $this->isOverdue(),
            'created_by' => $this->created_by,
            'assignee_id' => $this->assignee_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            // The full sets. `department_id` / `assignee_id` above are the
            // first of each, for clients that show only one name.
            'department_ids' => $this->whenLoaded('departments', fn () => $this->departments->pluck('id')),
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),
            'assignee_ids' => $this->whenLoaded('assignees', fn () => $this->assignees->pluck('id')),
            'assignees' => UserResource::collection($this->whenLoaded('assignees')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'checklist' => ChecklistItemResource::collection($this->whenLoaded('checklist')),
            'checklist_count' => $this->whenCounted('checklist'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
