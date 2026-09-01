<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistItemResource;
use App\Models\ChecklistItem;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checklist items hang off a task and borrow its permissions: anyone who may
 * edit the task may tick its boxes.
 */
class ChecklistController extends Controller
{
    public function index(Task $task): AnonymousResourceCollection
    {
        $this->authorize('view', $task);

        return ChecklistItemResource::collection($task->checklist);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $item = $task->checklist()->create([
            'title' => $data['title'],
            // New items land at the end unless told otherwise.
            'position' => $data['position'] ?? ($task->checklist()->max('position') + 1),
        ]);

        return response()->json(['data' => new ChecklistItemResource($item)], 201);
    }

    public function update(Request $request, ChecklistItem $checklistItem): JsonResponse
    {
        $this->authorize('update', $checklistItem->task);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'completed' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        $checklistItem->update($data);

        return response()->json(['data' => new ChecklistItemResource($checklistItem)]);
    }

    public function destroy(ChecklistItem $checklistItem): JsonResponse
    {
        $this->authorize('update', $checklistItem->task);

        $checklistItem->delete();

        return response()->json(['message' => 'Checklist item deleted.']);
    }
}
