<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('malan_campaign_guardian_numbers')) {
            return;
        }

        Schema::create('malan_campaign_guardian_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('malan_campaigns')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_chatbot_conversations')->nullOnDelete();
            $table->foreignId('source_contact_id')->nullable()->constrained('malan_campaign_contacts')->nullOnDelete();
            $table->string('phone', 40);
            $table->string('phone_normalized', 40);
            $table->string('source_name')->nullable();
            $table->string('source_phone', 40)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'phone_normalized']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('malan_campaign_guardian_numbers');
    }
};
