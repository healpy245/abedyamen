<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mansour_send_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->unsignedSmallInteger('sent')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);
            $table->string('note')->nullable();
            $table->json('results');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['instance_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mansour_send_runs');
    }
};
