<?php

use Illuminate\Database\Migrations\Migration;

/**
 * The first pass missed every multi-line replacement on prompts that were saved
 * through the settings textarea, because those are stored with CRLF endings.
 * The refresh is idempotent, so re-running it finishes the leftovers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $refresh = require __DIR__.'/2026_08_26_120000_refresh_malan_campaign_intro_copy.php';
        $refresh->up();
    }

    public function down(): void
    {
        // Prompt copy change only — nothing to roll back.
    }
};
