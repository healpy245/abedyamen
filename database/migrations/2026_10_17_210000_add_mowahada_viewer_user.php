<?php

use Database\Seeders\MowahadaViewerSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new MowahadaViewerSeeder)->run();
    }

    public function down(): void
    {
        $userId = \App\Models\User::query()->where('email', MowahadaViewerSeeder::EMAIL)->value('id');
        if ($userId === null) {
            return;
        }

        \App\Models\AiChatbot\ChatbotInstanceUser::query()->where('user_id', $userId)->delete();
        \App\Models\User::query()->where('id', $userId)->delete();
    }
};
