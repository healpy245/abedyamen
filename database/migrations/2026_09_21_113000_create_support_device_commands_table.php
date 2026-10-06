<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_device_commands')) {
            return;
        }

        Schema::create('support_device_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_device_id')->constrained('support_devices')->cascadeOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('name', 64);
            $table->json('args')->nullable();
            $table->string('status', 24)->default('queued');
            $table->json('result')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['support_device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_device_commands');
    }
};
