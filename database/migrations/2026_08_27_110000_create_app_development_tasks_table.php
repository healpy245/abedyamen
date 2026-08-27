<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained('app_development_tickets')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('todo');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->index(['assignee_id', 'status']);
            $table->index(['ticket_id', 'status']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_tasks');
    }
};
