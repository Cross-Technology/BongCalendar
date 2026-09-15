<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            /*
             * An image dropped into the middle of a note is still an
             * attachment — same private disk, same policy, same cleanup — but
             * it is already visible in the body, so the file list must not
             * repeat it. One flag separates "a file hanging off this note"
             * from "a picture inside it".
             */
            $table->boolean('is_embedded')->default(false)->after('attachable_id');

            /*
             * Nullable because an image is uploaded while the note is still
             * being written: it needs a URL to render at the cursor before
             * there is a note id to hang it off. The row is owned by its
             * uploader and tenant until save adopts it, and an orphan is
             * readable by nobody else (see AttachmentPolicy).
             */
            $table->string('attachable_type')->nullable()->change();
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
        });

        Schema::table('attachments', function (Blueprint $table) {
            // Save sweeps the uploader's orphans looking for the ones the body
            // actually references; without this it is a full table scan.
            $table->index(['uploaded_by', 'is_embedded', 'attachable_id'], 'attachments_orphan_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_orphan_sweep_index');
        });

        // Orphans have no parent to go back to, so they cannot survive a
        // column that is NOT NULL again.
        DB::table('attachments')->whereNull('attachable_id')->delete();

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('is_embedded');
            $table->string('attachable_type')->nullable(false)->change();
            $table->unsignedBigInteger('attachable_id')->nullable(false)->change();
        });
    }
};
