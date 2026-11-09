<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only what identifies the client's card is kept: brand, last 4 digits, expiry and holder.
     * The full number and CVV never reach the server; charging will go through the payment provider.
     */
    public function up(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->string('card_brand', 20)->nullable()->after('has_payment_method');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->string('card_expiry', 5)->nullable()->after('card_last4');
            $table->string('card_holder', 80)->nullable()->after('card_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->dropColumn(['card_brand', 'card_last4', 'card_expiry', 'card_holder']);
        });
    }
};
