<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Sanitised HTML, designed in the same editor as a report and
            // rendered above every report this department files. Null means
            // the department has not styled one and reports render bare.
            $table->longText('report_header')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('report_header');
        });
    }
};
