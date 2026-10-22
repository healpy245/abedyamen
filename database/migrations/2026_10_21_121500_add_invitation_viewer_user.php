<?php

use Database\Seeders\InvitationViewerSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new InvitationViewerSeeder)->run();
    }

    public function down(): void
    {
        $userId = \App\Models\User::query()->where('email', InvitationViewerSeeder::EMAIL)->value('id');
        if ($userId === null) {
            return;
        }

        \App\Models\AiChatbot\ChatbotInstanceUser::query()->where('user_id', $userId)->delete();
        \App\Models\User::query()->where('id', $userId)->delete();
    }
};
