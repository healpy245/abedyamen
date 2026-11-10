<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per kitchen ticket that did not print on one kitchen printer.
 * Built from the POS `printer` live logs (`kitchen_print_failed`,
 * `kitchen_print_resolved`, `kitchen_print_dismissed`). Kept apart from the
 * device logs so the per-device log trim never drops a printer issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_printer_issues')) {
            return;
        }

        Schema::create('support_printer_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_device_id')->nullable()->constrained('support_devices')->nullOnDelete();
            $table->string('restaurant', 120)->index();
            $table->string('incident', 191);
            $table->string('status', 16)->default('open');
            $table->string('fault', 32)->default('unknown');
            $table->string('fault_class', 16)->nullable();
            $table->boolean('may_have_printed')->default(false);
            $table->string('station', 120)->nullable();
            $table->string('station_id', 120)->nullable();
            $table->string('printer_ip', 120)->nullable();
            $table->unsignedInteger('printer_port')->nullable();
            $table->string('printer_mac', 64)->nullable();
            $table->string('printer_link', 32)->nullable();
            $table->string('order_id', 120)->nullable();
            $table->unsignedInteger('order_number')->nullable();
            $table->string('order_table', 64)->nullable();
            $table->string('order_channel', 32)->nullable();
            $table->string('cashier_name', 120)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->text('reason')->nullable();
            $table->text('error_detail')->nullable();
            $table->json('meals')->nullable();
            $table->json('attempts_log')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('dismissed_count')->default(0);
            $table->timestamp('first_failed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->string('resolved_by', 32)->nullable();
            $table->timestamps();

            $table->unique(['support_device_id', 'incident'], 'support_printer_issues_device_incident_unique');
            $table->index(['status', 'last_failed_at']);
            $table->index(['restaurant', 'last_failed_at']);
            $table->index('fault');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_printer_issues');
    }
};
