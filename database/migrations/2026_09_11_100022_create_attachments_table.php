<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // Carried on the row itself so a file can be scoped to a workspace
            // without loading whatever it hangs off.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Polymorphic: notes today, and reports are the obvious next one.
            $table->morphs('attachable');

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('disk')->default('local');
            $table->string('path');

            // What the uploader called it. Never used to build a path — the
            // stored name is generated, so a crafted filename cannot traverse
            // out of the directory or overwrite anything.
            $table->string('original_name');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size');

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
