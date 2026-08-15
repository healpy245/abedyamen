<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('ai_chatbot_conversations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['instance_id', 'action', 'created_at'], 'ai_chatbot_audit_logs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_audit_logs');
    }
};
