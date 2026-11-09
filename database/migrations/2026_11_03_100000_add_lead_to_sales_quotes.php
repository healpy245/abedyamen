<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A quote is for a client or for a lead. Converting the lead moves the quote onto the client.
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('finance_client_id')->nullable()->change();
        });

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->foreignId('agent_lead_id')->nullable()->after('finance_client_id')->constrained('agent_leads')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('sales_quotes')->whereNull('finance_client_id')->delete();

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_lead_id');
        });

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('finance_client_id')->nullable(false)->change();
        });
    }
};
