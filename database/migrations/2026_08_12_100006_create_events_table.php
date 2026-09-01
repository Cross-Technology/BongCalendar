<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('color', 7)->nullable();
            // Always stored in UTC; `timezone` records the wall-clock zone it was entered in.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone')->default('UTC');
            $table->boolean('all_day')->default(false);
            $table->string('status')->default('confirmed'); // confirmed | tentative | cancelled
            $table->string('recurrence_rule')->nullable();  // RFC 5545 RRULE
            $table->dateTime('recurrence_until')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['calendar_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
