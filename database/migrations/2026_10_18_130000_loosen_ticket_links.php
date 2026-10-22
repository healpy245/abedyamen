<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_development_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('ticket_id')->nullable()->change();
        });

        Schema::table('app_development_tickets', function (Blueprint $table): void {
            $table->string('restaurant_name', 191)->nullable();
            $table->unsignedBigInteger('support_ticket_id')->nullable()->index();
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_note')->nullable();
            $table->unsignedBigInteger('escalated_ticket_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'close_note', 'escalated_ticket_id']);
        });

        Schema::table('app_development_tickets', function (Blueprint $table): void {
            $table->dropColumn(['restaurant_name', 'support_ticket_id']);
        });
    }
};
