<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_ticket_app_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained('app_development_tickets')
                ->cascadeOnDelete();
            $table->string('app_type', 32);
            $table->timestamps();

            $table->unique(['ticket_id', 'app_type']);
            $table->index('app_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_ticket_app_types');
    }
};
