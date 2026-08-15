<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->string('channel', 32)->default('web')->after('title');
            $table->string('external_chat_id')->nullable()->after('channel');
            $table->string('contact_phone', 64)->nullable()->after('external_chat_id');
            $table->string('contact_name')->nullable()->after('contact_phone');
            $table->text('contact_avatar_url')->nullable()->after('contact_name');
            $table->timestamp('last_message_at')->nullable()->after('contact_avatar_url');
            $table->timestamp('last_customer_message_at')->nullable()->after('last_message_at');
            $table->timestamp('last_assistant_message_at')->nullable()->after('last_customer_message_at');
            $table->unsignedInteger('unread_count')->default(0)->after('last_assistant_message_at');
            $table->string('attention_status', 32)->default('normal')->after('unread_count');
            $table->string('bot_mode', 32)->default('active')->after('attention_status');
            $table->foreignId('assigned_user_id')->nullable()->after('bot_mode')
                ->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable()->after('assigned_user_id');
        });

        // Unique per WhatsApp/external chat; multiple NULL external_chat_id rows are allowed
        // (MySQL/SQLite/Postgres treat NULL as distinct in unique indexes).
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->unique(
                ['instance_id', 'channel', 'external_chat_id'],
                'ai_chatbot_conversations_instance_channel_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropUnique('ai_chatbot_conversations_instance_channel_external_unique');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn([
                'channel',
                'external_chat_id',
                'contact_phone',
                'contact_name',
                'contact_avatar_url',
                'last_message_at',
                'last_customer_message_at',
                'last_assistant_message_at',
                'unread_count',
                'attention_status',
                'bot_mode',
                'metadata',
            ]);
        });
    }
};
