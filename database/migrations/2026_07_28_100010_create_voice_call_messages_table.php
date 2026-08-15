<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_call_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_call_id')
                ->constrained('voice_calls')
                ->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->string('provider_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('provider_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_messages');
    }
};
