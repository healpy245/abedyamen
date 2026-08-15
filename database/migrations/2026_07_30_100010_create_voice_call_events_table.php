<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_call_id')->constrained('voice_calls')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('metric_key', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['voice_call_id', 'event_type']);
            $table->index(['voice_call_id', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_events');
    }
};
