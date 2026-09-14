<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can be shared between departments and picked up by more than one
 * person. The `tasks.department_id` / `tasks.assignee_id` columns stay as the
 * *primary* of each set — the first pick — so every existing read still
 * answers; these tables carry the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'task_id']);
            $table->index('task_id');
        });

        Schema::create('task_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });

        // Existing filings become the first member of their set.
        DB::statement('
            INSERT INTO department_task (department_id, task_id, created_at, updated_at)
            SELECT department_id, id, created_at, updated_at FROM tasks WHERE department_id IS NOT NULL
        ');

        DB::statement('
            INSERT INTO task_user (task_id, user_id, created_at, updated_at)
            SELECT id, assignee_id, created_at, updated_at FROM tasks WHERE assignee_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_user');
        Schema::dropIfExists('department_task');
    }
};
