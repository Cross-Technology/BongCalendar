<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            // Bodies become markup, which runs longer than the plain text did.
            $table->longText('body')->change();

            // Plain-text rendering for search and previews: searching the
            // markup would match tag names and miss any phrase a tag splits.
            $table->longText('body_text')->nullable()->after('body');
        });

        $this->convertExistingNotes();
    }

    /**
     * Existing bodies are plain text. They are escaped on the way into HTML —
     * a note that happened to contain "<script>" was inert as text and must
     * stay inert as markup, not become live script for the whole workspace.
     */
    protected function convertExistingNotes(): void
    {
        DB::table('notes')->orderBy('id')->chunkById(200, function ($notes) {
            foreach ($notes as $note) {
                $plain = (string) $note->body;

                $lines = preg_split('/\r\n|\r|\n/', trim($plain)) ?: [];

                $html = implode('', array_map(
                    fn (string $line) => '<div>'.($line === '' ? '<br>' : e($line)).'</div>',
                    $lines,
                ));

                DB::table('notes')->where('id', $note->id)->update([
                    'body' => $html,
                    'body_text' => trim((string) preg_replace('/\s+/u', ' ', $plain)),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Put the plain text back where the markup came from.
        DB::table('notes')->whereNotNull('body_text')->orderBy('id')->chunkById(200, function ($notes) {
            foreach ($notes as $note) {
                DB::table('notes')->where('id', $note->id)->update(['body' => $note->body_text]);
            }
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('body_text');
            $table->text('body')->change();
        });
    }
};
