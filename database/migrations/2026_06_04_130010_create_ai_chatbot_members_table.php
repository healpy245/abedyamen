<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_chatbot_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')
                ->constrained('ai_chatbot_instances')
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('customer_type', ['new', 'subscriber'])->default('new');
            $table->string('payment_last4', 4)->nullable();
            $table->string('router_type')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'national_id']);
            $table->index(['instance_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_members');
    }
};
