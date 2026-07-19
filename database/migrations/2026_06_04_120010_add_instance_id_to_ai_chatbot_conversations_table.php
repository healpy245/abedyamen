<?php

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\AiChatbot\AiChatbotSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->foreignId('instance_id')
                ->nullable()
                ->after('user_id')
                ->constrained('ai_chatbot_instances')
                ->cascadeOnDelete();
        });

        $settings = app(AiChatbotSettingsService::class);
        $defaultPrompt = (string) ($settings->defaults()['system_prompt'] ?? 'You are a helpful AI assistant.');

        $userIds = ChatbotConversation::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            if (!User::query()->whereKey($userId)->exists()) {
                continue;
            }

            $instance = ChatbotInstance::query()->create([
                'user_id' => $userId,
                'name' => 'General Assistant',
                'system_prompt' => $defaultPrompt,
            ]);

            ChatbotConversation::query()
                ->where('user_id', $userId)
                ->whereNull('instance_id')
                ->update(['instance_id' => $instance->id]);
        }

        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropForeign(['instance_id']);
        });

        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->foreignId('instance_id')->nullable(false)->change();
        });

        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->foreign('instance_id')
                ->references('id')
                ->on('ai_chatbot_instances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instance_id');
        });
    }
};
