<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('officevision_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->string('stream_base')->nullable();
            $table->string('stream_user')->nullable();
            $table->string('stream_password')->nullable();
            $table->string('turn_url')->nullable();
            $table->string('turn_username')->nullable();
            $table->string('turn_credential')->nullable();
            $table->json('cameras')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('officevision_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('officevision_devices')->cascadeOnDelete();
            $table->string('event_uid', 64);
            $table->string('camera_name');
            $table->string('event_type', 16);
            $table->string('worker_name')->nullable();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->timestamp('occurred_at');
            $table->string('clip_path')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'event_uid']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('officevision_events');
        Schema::dropIfExists('officevision_devices');
    }
};
