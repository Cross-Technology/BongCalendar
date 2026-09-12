<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // `lead` is who the department answers for. It carries no extra
            // permission today — department membership scopes work, it does
            // not gate it — but it decides whose department a task lands in
            // when someone belongs to more than one.
            $table->string('role')->default('member');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
    }
};
