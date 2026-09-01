<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#6f5cf0');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Slugs only need to be unique inside a workspace.
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'position']);
        });

        Schema::table('calendars', function (Blueprint $table) {
            // Nullable: a calendar without a department is "ungrouped", which is
            // what every existing calendar becomes.
            $table->foreignId('department_id')
                ->nullable()
                ->after('owner_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['tenant_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropIndex(['tenant_id', 'department_id']);
            $table->dropColumn('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
