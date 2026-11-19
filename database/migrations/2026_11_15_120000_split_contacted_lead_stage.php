<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Contacted" now has three sub-statuses: not answering, will decide on a meeting, not interested.
     * Leads already contacted had no outcome recorded, so they become "will decide on a meeting".
     */
    public function up(): void
    {
        DB::table('agent_leads')->where('stage', 'contacted')->update(['stage' => 'deciding']);
    }

    public function down(): void
    {
        DB::table('agent_leads')->whereIn('stage', ['no_answer', 'deciding', 'not_interested'])->update(['stage' => 'contacted']);
    }
};
