<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            // What the template is called in the picker, and what the task it
            // stamps out is called. Usually the same, deliberately separable.
            $table->string('name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->string('priority')->default('medium');
            $table->json('tags')->nullable();
            // Checklist titles, recreated on every task made from this template.
            $table->json('checklist')->nullable();

            // Sorts the picker so what you use daily stays at the top.
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
