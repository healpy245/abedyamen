<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phones signed in to the Kaman Workspace app that can take a push (Firebase
 * Cloud Messaging), with what each phone chose to hear about.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_push_devices')) {
            return;
        }

        Schema::create('workspace_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 16)->default('android');
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 8)->default('en');
            $table->boolean('notify_tickets')->default(true);
            $table->boolean('notify_failures')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_push_devices');
    }
};
