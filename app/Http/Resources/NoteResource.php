<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NoteResource extends JsonResource
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
            'author_id' => $this->author_id,
            'title' => $this->title,
            'display_title' => $this->displayTitle(),
            'body' => $this->body,
            'excerpt' => $this->excerpt(),
            'color' => $this->color,
            'visibility' => $this->visibility,
            'is_pinned' => $this->is_pinned,
            'is_mine' => $user ? $this->author_id === $user->id : false,
            // Lets a client hide the edit affordance without a second call.
            'can_edit' => $user ? $user->can('update', $this->resource) : false,
            'author' => new UserResource($this->whenLoaded('author')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
