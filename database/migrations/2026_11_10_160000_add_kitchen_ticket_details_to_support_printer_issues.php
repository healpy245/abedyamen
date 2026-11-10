<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Details the POS already sends on a kitchen-print failure: order type,
 * external order id (Haat and the rest), printer name, kitchens that did
 * print, and the kitchens configured when none of them matched the meals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_printer_issues')) {
            return;
        }

        Schema::table('support_printer_issues', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_printer_issues', 'order_type')) {
                $table->string('order_type', 32)->nullable();
            }
            if (! Schema::hasColumn('support_printer_issues', 'external_order_id')) {
                $table->string('external_order_id', 120)->nullable();
            }
            if (! Schema::hasColumn('support_printer_issues', 'printer_name')) {
                $table->string('printer_name', 120)->nullable();
            }
            if (! Schema::hasColumn('support_printer_issues', 'printed_on')) {
                $table->json('printed_on')->nullable();
            }
            if (! Schema::hasColumn('support_printer_issues', 'configured_printers')) {
                $table->json('configured_printers')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_printer_issues')) {
            return;
        }

        Schema::table('support_printer_issues', function (Blueprint $table): void {
            $drop = array_values(array_filter(
                ['order_type', 'external_order_id', 'printer_name', 'printed_on', 'configured_printers'],
                static fn (string $column): bool => Schema::hasColumn('support_printer_issues', $column),
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
