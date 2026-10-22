<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mansour_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 32);
            $table->string('phone_normalized', 32)->nullable();
            $table->string('chat_id', 64)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_chatbot_conversations')->nullOnDelete();
            $table->timestamp('triggered_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'phone_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mansour_contacts');
    }
};
