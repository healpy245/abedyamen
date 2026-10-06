<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 64);
            $table->string('restaurant', 120)->default('');
            $table->string('restaurant_name', 191)->nullable();
            $table->string('cashier_name', 191)->nullable();
            $table->string('device_id', 191)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('summary', 2000);
            $table->longText('details')->nullable();
            $table->json('conversation')->nullable();
            $table->json('diagnosis')->nullable();
            $table->json('printers')->nullable();
            $table->json('extra')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('assignee', 191)->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant', 'public_id'], 'support_tickets_restaurant_public_id_unique');
            $table->index('public_id');
            $table->index('status');
            $table->index(['restaurant', 'created_at']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->string('actor_type', 32)->default('system');
            $table->string('actor_name', 191)->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });

        Schema::create('support_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('restaurant', 120);
            $table->string('device_id', 191);
            $table->string('last_ticket_id', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant', 'device_id'], 'support_devices_restaurant_device_unique');
            $table->index('last_seen_at');
        });

        Schema::create('support_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_devices');
        Schema::dropIfExists('support_workers');
        Schema::dropIfExists('support_tickets');
    }
};
