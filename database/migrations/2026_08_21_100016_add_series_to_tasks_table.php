<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            /*
             * Repeating tasks are materialised: one real row per occurrence, so
             * each day can be completed, edited and checklisted on its own.
             * This ties them together for "edit the rest" and "delete them all".
             */
            $table->uuid('series_id')->nullable()->after('parent_task_id');
            $table->index(['tenant_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'series_id']);
            $table->dropColumn('series_id');
        });
    }
};
