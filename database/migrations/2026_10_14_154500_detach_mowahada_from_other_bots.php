<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->where('email', 'mowahada@workspace.com')->value('id');
        if ($userId === null) {
            return;
        }

        $ownIds = DB::table('ai_chatbot_instances')
            ->where('user_id', $userId)
            ->where('integration_type', 'mansour')
            ->pluck('id');

        $grants = DB::table('ai_chatbot_instance_user')->where('user_id', $userId);
        if ($ownIds->isNotEmpty()) {
            $grants->whereNotIn('instance_id', $ownIds);
        }
        $grants->delete();
    }

    public function down(): void
    {
        // Shared-bot grants are not restored.
    }
};
