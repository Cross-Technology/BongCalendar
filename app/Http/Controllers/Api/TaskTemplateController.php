<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TaskTemplateRequest;
use App\Http\Resources\TaskTemplateResource;
use App\Models\TaskTemplate;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskTemplateController extends Controller
{
    public function __construct(protected TenantContext $tenants) {}

    public function index(): AnonymousResourceCollection
    {
        return TaskTemplateResource::collection(
            TaskTemplate::forTenant($this->tenants->require()->id)->get()
        );
    }

    public function store(TaskTemplateRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $this->authorize('create', [TaskTemplate::class, $tenant->id]);

        $data = $request->validated();

        $template = TaskTemplate::create($data + [
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()->id,
            // A template with no name of its own is known by what it makes.
            'name' => $data['name'] ?? $data['title'],
        ]);

        return response()->json(['data' => new TaskTemplateResource($template)], 201);
    }

    public function show(TaskTemplate $taskTemplate): JsonResponse
    {
        $this->authorize('view', $taskTemplate);

        return response()->json(['data' => new TaskTemplateResource($taskTemplate)]);
    }

    public function update(TaskTemplateRequest $request, TaskTemplate $taskTemplate): JsonResponse
    {
        $this->authorize('update', $taskTemplate);

        $taskTemplate->update($request->validated());

        return response()->json(['data' => new TaskTemplateResource($taskTemplate)]);
    }

    public function destroy(TaskTemplate $taskTemplate): JsonResponse
    {
        $this->authorize('delete', $taskTemplate);

        $taskTemplate->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * Records that a template was stamped onto a day. Separate from update so
     * using a routine you did not write does not require permission to edit it.
     */
    public function markUsed(Request $request, TaskTemplate $taskTemplate): JsonResponse
    {
        $this->authorize('use', $taskTemplate);

        $taskTemplate->markUsed();

        return response()->json(['data' => new TaskTemplateResource($taskTemplate)]);
    }
}
