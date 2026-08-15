<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_messages', function (Blueprint $table) {
            $table->string('sender_type', 32)->nullable()->after('role');
            $table->string('external_message_id')->nullable()->after('sender_type');
            $table->string('message_type', 32)->default('text')->after('external_message_id');
            $table->foreignId('sent_by_user_id')->nullable()->after('message_type')
                ->constrained('users')->nullOnDelete();
            $table->string('reply_source', 32)->nullable()->after('sent_by_user_id');
            $table->string('delivery_status', 32)->nullable()->after('reply_source');
            $table->timestamp('read_at')->nullable()->after('delivery_status');
            $table->json('metadata')->nullable()->after('read_at');

            $table->index(['conversation_id', 'external_message_id'], 'ai_chatbot_messages_conv_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_messages', function (Blueprint $table) {
            $table->dropIndex('ai_chatbot_messages_conv_external_idx');
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn([
                'sender_type',
                'external_message_id',
                'message_type',
                'reply_source',
                'delivery_status',
                'read_at',
                'metadata',
            ]);
        });
    }
};
