<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            // Only one level of nesting, matching the mobile app's subtasks.
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            // A running note on the task, distinct from what the task *is*.
            $table->text('note')->nullable();

            $table->string('status')->default('todo');      // todo | in_progress | done | blocked
            $table->string('priority')->default('medium');  // low | medium | high | urgent

            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The board reads by status; the calendar reads by due date.
            $table->index(['tenant_id', 'status', 'position']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'department_id']);
            $table->index('assignee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
