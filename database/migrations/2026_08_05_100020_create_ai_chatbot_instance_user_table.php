<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_instance_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('agent');
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'user_id'], 'ai_chatbot_instance_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_instance_user');
    }
};
