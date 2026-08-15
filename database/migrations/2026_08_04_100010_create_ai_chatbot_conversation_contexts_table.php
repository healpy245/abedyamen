<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_conversation_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('ai_chatbot_conversations')->cascadeOnDelete();
            $table->foreignId('chatbot_instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->string('verified_customer_id')->nullable();
            $table->string('verified_customer_name')->nullable();
            $table->string('verified_phone_masked')->nullable();
            $table->string('verified_identity_masked')->nullable();
            $table->string('customer_status')->nullable();
            $table->decimal('debt_amount', 12, 2)->nullable();
            $table->string('pending_flow')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(
                ['chatbot_instance_id', 'verified_customer_id'],
                'ai_cb_ctx_instance_customer_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_conversation_contexts');
    }
};
