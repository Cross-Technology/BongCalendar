<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'human_size' => $this->humanSize(),
            'extension' => $this->extension(),
            'is_image' => $this->isImage(),
            'uploaded_by' => $this->uploaded_by,
            'uploader' => new UserResource($this->whenLoaded('uploader')),
            // The dashboard reads through the session route; API clients hold
            // a bearer token, which the web route would not accept.
            'download_url' => $request->is('api/*')
                ? url("/api/v1/attachments/{$this->id}")
                : $this->downloadUrl(),
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'created_at' => $this->created_at,
        ];
    }
}
