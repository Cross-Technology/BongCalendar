<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail. One row per thing that happened to a task or a report —
 * who did it, when, and what changed.
 *
 * Polymorphic because the question ("who touched this, and when?") is the same
 * whatever is being asked about, and a second table per subject would mean a
 * second set of every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Carried on the row rather than read through the subject, so a
            // workspace's whole trail is one query and stays readable after
            // the subject itself is gone.
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->morphs('subject');

            // Nullable: the seeder, a console command and the recurrence job
            // all write tasks with nobody signed in, and "nobody" is a truer
            // record than pinning it on whoever happens to be the owner.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 32);           // created | updated | deleted | restored

            // Per changed field: its label and, unless the field is too big to
            // be worth keeping, what it went from and to. Rendered as written —
            // an audit entry is a record of that moment, so it must not change
            // later because a department was renamed.
            $table->json('change_set')->nullable();

            // Written once, never edited, so there is no updated_at to keep.
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_subject_index');
            $table->index(['tenant_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
