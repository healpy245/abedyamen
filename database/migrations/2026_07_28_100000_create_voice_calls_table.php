<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_instance_id')
                ->constrained('ai_chatbot_instances')
                ->cascadeOnDelete();
            $table->string('provider', 32)->default('fake');
            $table->string('provider_call_id')->nullable();
            $table->string('caller_number')->nullable();
            $table->string('called_number')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('chatbot_conversation_id')
                ->nullable()
                ->constrained('ai_chatbot_conversations')
                ->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider_call_id');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_calls');
    }
};
