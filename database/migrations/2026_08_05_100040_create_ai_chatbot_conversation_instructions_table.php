<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_conversation_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_chatbot_conversations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->longText('instruction');
            $table->string('scope', 32)->default('next_reply');
            $table->unsignedInteger('remaining_uses')->nullable();
            $table->integer('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'is_active', 'priority'], 'ai_chatbot_instructions_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_conversation_instructions');
    }
};
