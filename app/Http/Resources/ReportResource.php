<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'department_id' => $this->department_id,
            'report_date' => $this->report_date?->toDateString(),
            // Already sanitised on the way in — safe to render as markup.
            'body' => $this->body,
            'body_text' => $this->body_text,
            'excerpt' => $this->excerpt(),
            'author_id' => $this->author_id,
            'last_editor_id' => $this->last_editor_id,
            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'author' => new UserResource($this->whenLoaded('author')),
            'last_editor' => new UserResource($this->whenLoaded('lastEditor')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            // Read receipts, only when they were asked for — an index that
            // loaded them for every row would be a query per report.
            'seen_count' => $this->whenLoaded('views', fn () => $this->views->count()),
            'seen_by' => $this->whenLoaded('views', fn () => $this->views->map(fn ($view) => [
                'user_id' => $view->user_id,
                'name' => $view->user?->name,
                'first_seen_at' => $view->first_seen_at,
                'last_seen_at' => $view->last_seen_at,
                'views' => $view->views,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
