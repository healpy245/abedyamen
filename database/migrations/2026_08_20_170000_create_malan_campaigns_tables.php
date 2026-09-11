<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('malan_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatbot_instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('status', 32)->default('draft'); // draft|ready|running|stopped|completed
            $table->text('greenapi_url')->nullable();
            $table->string('greenapi_webhook_token', 64)->unique();
            $table->text('opening_message');
            $table->string('excel_disk', 32)->nullable();
            $table->string('excel_path')->nullable();
            $table->string('excel_original_name')->nullable();
            $table->unsignedInteger('contacts_count')->default(0);
            $table->unsignedInteger('messages_triggered_count')->default(0);
            $table->unsignedInteger('responded_count')->default(0);
            $table->unsignedInteger('leads_stored_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['chatbot_instance_id', 'status']);
        });

        Schema::create('malan_campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('malan_campaigns')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 40);
            $table->string('phone_normalized', 40)->nullable();
            $table->string('city')->nullable();
            $table->string('chat_id')->nullable();
            $table->string('status', 32)->default('pending'); // pending|queued|sent|failed|responded|lead_created|skipped
            $table->foreignId('conversation_id')->nullable()->constrained('ai_chatbot_conversations')->nullOnDelete();
            $table->timestamp('message_sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('lead_created_at')->nullable();
            $table->unsignedBigInteger('malan_lead_id')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'phone_normalized']);
        });

        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->foreignId('campaign_id')
                ->nullable()
                ->after('instance_id')
                ->constrained('malan_campaigns')
                ->nullOnDelete();
            $table->index(['instance_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('malan_campaign_contacts');
        Schema::dropIfExists('malan_campaigns');
    }
};
