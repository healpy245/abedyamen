<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_tool_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_chatbot_conversations')->cascadeOnDelete();
            $table->foreignId('chatbot_instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->string('tool_name', 100);
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->boolean('success')->default(false);
            $table->string('external_reference')->nullable();
            $table->string('channel', 30)->nullable();
            $table->timestamps();

            $table->index(['chatbot_instance_id', 'tool_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_tool_executions');
    }
};
