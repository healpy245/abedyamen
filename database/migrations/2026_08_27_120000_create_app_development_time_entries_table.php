<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')
                ->constrained('app_development_tasks')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('edited_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('edit_reason')->nullable();
            $table->unsignedInteger('original_duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_time_entries');
    }
};
