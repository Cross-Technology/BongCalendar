<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // A note always keeps its author: the byline is the whole point of
            // a shared noticeboard. Removing the user removes their notes.
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->text('body');
            $table->string('color', 7)->default('#f59e0b');

            // Mirrors calendars.visibility: `tenant` is readable by every
            // member, `private` only by the author.
            $table->enum('visibility', ['private', 'tenant'])->default('tenant');
            $table->boolean('is_pinned')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // The list is always "this workspace, pinned first, newest first".
            $table->index(['tenant_id', 'is_pinned', 'updated_at']);
            // Filtering the board down to one person's notes.
            $table->index(['tenant_id', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
