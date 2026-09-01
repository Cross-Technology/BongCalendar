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
             * The day the work is scheduled for, as opposed to the deadline.
             * The calendar places a task by this and falls back to the due
             * date, so a task with either one still has somewhere to appear.
             */
            $table->timestamp('start_date')->nullable()->after('note');
            $table->index(['tenant_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'start_date']);
            $table->dropColumn('start_date');
        });
    }
};
