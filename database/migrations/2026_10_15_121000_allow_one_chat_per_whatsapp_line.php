<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropUnique('ai_chatbot_conversations_instance_channel_external_unique');
            $table->index(
                ['instance_id', 'channel', 'external_chat_id'],
                'ai_chatbot_conversations_instance_channel_external_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropIndex('ai_chatbot_conversations_instance_channel_external_index');
            $table->unique(
                ['instance_id', 'channel', 'external_chat_id'],
                'ai_chatbot_conversations_instance_channel_external_unique',
            );
        });
    }
};
