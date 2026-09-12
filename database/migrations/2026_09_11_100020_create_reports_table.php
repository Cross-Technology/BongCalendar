<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            // Who opened the report, and whoever touched it last. A day's
            // report is shared, so those are rarely the same person.
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_editor_id')->nullable()->constrained('users')->nullOnDelete();

            // The day being reported on, not the day it was typed.
            $table->date('report_date');

            // Sanitised HTML from the editor, plus a plain-text rendering used
            // for search and previews — searching the HTML would match tag
            // names and miss any phrase a bold tag happens to split.
            $table->longText('body');
            $table->longText('body_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One report per department per day. deleted_at rides along so a
            // deleted report does not block writing that day again: MySQL
            // treats each NULL as distinct, so only live rows collide.
            $table->unique(['tenant_id', 'department_id', 'report_date', 'deleted_at'], 'reports_day_unique');
            $table->index(['tenant_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
