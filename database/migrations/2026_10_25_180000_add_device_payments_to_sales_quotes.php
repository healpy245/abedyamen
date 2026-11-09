<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices can be paid in parts. A sale remembers the device total (VAT included) and what is still owed;
     * every payment towards it is a Finance invoice linked back to the sale.
     */
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->decimal('devices_total', 12, 2)->default(0)->after('one_time_total');
            $table->decimal('devices_due', 12, 2)->default(0)->after('devices_total');
        });

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->foreignId('sales_quote_id')->nullable()->after('finance_client_id')->constrained('sales_quotes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_quote_id');
        });

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropColumn(['devices_total', 'devices_due']);
        });
    }
};
