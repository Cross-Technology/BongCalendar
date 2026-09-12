<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-department report headers are gone. The column is dropped rather
     * than left behind so nothing reads a half-removed feature.
     *
     * Anything already written is lost — down() restores the column, not its
     * contents.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('report_header');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->longText('report_header')->nullable()->after('icon');
        });
    }
};
