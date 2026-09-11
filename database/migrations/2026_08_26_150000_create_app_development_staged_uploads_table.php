<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_staged_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('original_name');
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedBigInteger('received_size')->default(0);
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('kind', 16)->default('attachment'); // attachment|voice
            $table->string('status', 16)->default('pending'); // pending|ready|claimed|failed
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_staged_uploads');
    }
};
