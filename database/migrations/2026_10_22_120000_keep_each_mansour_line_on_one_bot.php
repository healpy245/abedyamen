<?php

use App\Services\AiChatbot\MansourLines;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A WhatsApp number belongs to one bot. Older saves could leave it on several;
     * keep it on the bot where it is switched on (or the first one) and remove it from the rest.
     */
    public function up(): void
    {
        app(MansourLines::class)->dedupe();
    }

    public function down(): void
    {
        // The removed duplicates were never switched on; nothing to put back.
    }
};
