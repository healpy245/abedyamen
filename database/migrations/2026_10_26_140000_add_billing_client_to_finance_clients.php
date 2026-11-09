<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several restaurants can share one MFITGROUP account: a linked restaurant points at the restaurant
 * that holds the account, and its payments, invoices, card and billing are the holder's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->foreignId('billing_client_id')->nullable()->after('mfit_client_id')
                ->constrained('finance_clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_client_id');
        });
    }
};
