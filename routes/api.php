<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskTemplateController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\WorkspaceInvitationController;
use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BongCalendar API (v1)
|--------------------------------------------------------------------------
| Stateless JWT. Send `Authorization: Bearer <token>` on protected routes,
| and `X-Tenant: <workspace-slug>` on tenant-scoped routes to override the
| caller's active workspace.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Deliberately outside `auth:api`: a client refreshes precisely because its
    // access token has expired, and that middleware rejects expired tokens. The
    // controller validates the token itself and honours `jwt.refresh_ttl`.
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');

    Route::middleware('auth:api')->group(function () {
        // Account-level: usable before a workspace is chosen.
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('workspaces', [TenantController::class, 'index']);
        Route::post('workspaces', [TenantController::class, 'store']);
        Route::get('workspaces/{tenant}', [TenantController::class, 'show']);
        Route::patch('workspaces/{tenant}', [TenantController::class, 'update']);
        Route::delete('workspaces/{tenant}', [TenantController::class, 'destroy']);
        Route::post('workspaces/{tenant}/switch', [TenantController::class, 'switch']);
        Route::get('workspaces/{tenant}/members', [TenantController::class, 'members']);

        // Invitations addressed to an email: nothing is granted until the
        // invitee answers, so these live beside the account, not the workspace.
        // Push devices and digest preferences belong to the account, so they
        // sit outside the workspace-scoped group.
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices', [DeviceController::class, 'destroy']);
        Route::patch('me/notifications', [DeviceController::class, 'updatePreferences']);

        Route::get('workspace-invitations', [WorkspaceInvitationController::class, 'mine']);
        Route::post('workspace-invitations/{invitation}/accept', [WorkspaceInvitationController::class, 'accept']);
        Route::post('workspace-invitations/{invitation}/decline', [WorkspaceInvitationController::class, 'decline']);

        // The workspace's own code: whoever holds it redeems it themselves.
        Route::get('workspace-codes/{code}', [WorkspaceInvitationController::class, 'preview']);
        Route::post('workspace-codes/join', [WorkspaceInvitationController::class, 'join'])->middleware('throttle:20,1');

        Route::get('workspaces/{tenant}/invitations', [WorkspaceInvitationController::class, 'index']);
        Route::post('workspaces/{tenant}/invitations', [WorkspaceInvitationController::class, 'store']);
        Route::delete('workspaces/{tenant}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy']);
        Route::post('workspaces/{tenant}/invite-code', [WorkspaceInvitationController::class, 'regenerateCode']);
        Route::post('workspaces/{tenant}/members', [TenantController::class, 'addMember']);
        Route::patch('workspaces/{tenant}/members/{user}', [TenantController::class, 'updateMemberRole']);
        Route::delete('workspaces/{tenant}/members/{user}', [TenantController::class, 'removeMember']);

        // Everything below resolves a workspace first.
        Route::middleware('tenant')->group(function () {
            // Names are prefixed so they cannot collide with the web page
            // routes, which already own `departments.*` and `calendars.*`.
            Route::apiResource('departments', DepartmentController::class)->names('api.departments');
            Route::post('departments/{department}/calendars', [DepartmentController::class, 'assign']);

            // Who works in a department. Membership scopes work — it decides
            // where a task lands and what the reports page leads with — rather
            // than hiding anything from the rest of the workspace.
            Route::get('departments/{department}/members', [DepartmentController::class, 'members']);
            Route::post('departments/{department}/members', [DepartmentController::class, 'addMember']);
            Route::delete('departments/{department}/members/{user}', [DepartmentController::class, 'removeMember']);

            Route::apiResource('calendars', CalendarController::class)->names('api.calendars');
            Route::get('calendars/{calendar}/shares', [CalendarController::class, 'shares']);
            Route::post('calendars/{calendar}/shares', [CalendarController::class, 'share']);
            Route::delete('calendars/{calendar}/shares/{user}', [CalendarController::class, 'revokeShare']);

            Route::apiResource('events', EventController::class)->names('api.events');

            // The workspace noticeboard. Pinning is its own endpoint for the
            // same reason task status is: it is the one field a client flips
            // on its own, without resubmitting the note.
            Route::apiResource('notes', NoteController::class)->names('api.notes');
            Route::post('notes/{note}/pin', [NoteController::class, 'pin']);

            // Files hanging off a note. Reads are streamed through the policy —
            // the disk is private, so there is no URL to share around.
            Route::post('notes/{note}/attachments', [AttachmentController::class, 'storeForNote']);
            Route::get('attachments/{attachment}', [AttachmentController::class, 'download']);
            Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

            // Daily department reports. `daily` is declared before the
            // resource so /reports/daily is not swallowed by /reports/{report}.
            Route::get('reports/daily', [ReportController::class, 'daily']);
            Route::apiResource('reports', ReportController::class)->names('api.reports');

            Route::apiResource('tasks', TaskController::class)->names('api.tasks');
            // Status is its own endpoint: it is the one field with a side
            // effect (completed_at) and the one clients change on its own.
            Route::patch('tasks/{task}/status', [TaskController::class, 'status']);

            // Repeating tasks: preview how many a rule makes, and delete the
            // whole run in one go.
            Route::apiResource('task-templates', TaskTemplateController::class)->names('api.task-templates');
            Route::post('task-templates/{taskTemplate}/used', [TaskTemplateController::class, 'markUsed']);

            Route::post('tasks/repeat-preview', [TaskController::class, 'previewRepeat']);
            Route::delete('task-series/{series}', [TaskController::class, 'destroySeries']);

            // Checklist items are addressed through their task on the way in,
            // and by their own id once they exist.
            Route::get('tasks/{task}/checklist', [ChecklistController::class, 'index']);
            Route::post('tasks/{task}/checklist', [ChecklistController::class, 'store']);
            Route::patch('checklist/{checklistItem}', [ChecklistController::class, 'update']);
            Route::delete('checklist/{checklistItem}', [ChecklistController::class, 'destroy']);

            Route::get('invitations', [InvitationController::class, 'index']);
            Route::post('events/{event}/respond', [InvitationController::class, 'respond']);
        });
    });
});
