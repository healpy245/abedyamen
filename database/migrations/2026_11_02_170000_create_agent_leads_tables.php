<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('phone', 40)->nullable();
            // kaman.rest area and company ids: those live outside this database.
            $table->string('city_id', 64)->nullable();
            $table->string('restaurant_id', 64)->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 20)->default('new')->index();
            $table->dateTime('next_follow_up_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Set on rows brought over from a browser's local storage, so a retried import adds nothing.
            $table->string('import_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('agent_lead_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_lead_id')->constrained('agent_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('text');
            $table->timestamps();
        });

        Schema::create('agent_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_lead_id')->nullable()->constrained('agent_leads')->cascadeOnDelete();
            $table->string('restaurant_id', 64)->nullable();
            $table->string('restaurant_name')->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('at')->index();
            $table->string('type', 20)->default('visit');
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('import_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_appointments');
        Schema::dropIfExists('agent_lead_notes');
        Schema::dropIfExists('agent_leads');
    }
};
