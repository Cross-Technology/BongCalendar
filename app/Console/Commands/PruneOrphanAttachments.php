<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Collects images uploaded into a note that was never saved.
 *
 * An image dropped into the editor is stored immediately — it needs a URL
 * before it can be drawn — so it exists before the note does. Save adopts it;
 * closing the tab instead leaves a row with no parent, which nobody can read
 * and nothing will ever free. This is what frees it.
 *
 * The default grace period is a day because a half-written note can sit open
 * overnight, and sweeping its images would blank the editor in front of
 * whoever is still writing.
 */
class PruneOrphanAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans
                            {--hours=24 : How old an unattached upload must be before it is swept}';

    protected $description = 'Delete uploads that were never attached to anything';

    public function handle(AttachmentService $attachments): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $removed = $attachments->pruneOrphans(CarbonImmutable::now()->subHours($hours));

        $this->info($removed === 0
            ? 'Nothing to prune.'
            : "Pruned {$removed} unattached ".str('upload')->plural($removed).'.');

        return self::SUCCESS;
    }
}
