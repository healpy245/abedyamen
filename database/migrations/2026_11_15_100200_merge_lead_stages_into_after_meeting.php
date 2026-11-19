<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Demo completed" and "Negotiation" are gone: those leads have had their meeting and are still deciding,
     * so they become "After meeting · Waiting to decide". Won and lost keep their values.
     */
    public function up(): void
    {
        DB::table('agent_leads')->whereIn('stage', ['demo', 'negotiation'])->update(['stage' => 'waiting']);
    }

    public function down(): void
    {
        DB::table('agent_leads')->where('stage', 'waiting')->update(['stage' => 'negotiation']);
    }
};
