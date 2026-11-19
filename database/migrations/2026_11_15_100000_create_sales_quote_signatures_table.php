<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contract link sent to the client. Signing keeps what the client saw (the snapshot) and the drawn
     * signature; sending again cancels the earlier link and its signature.
     */
    public function up(): void
    {
        Schema::create('sales_quote_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quote_id')->constrained('sales_quotes')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 12)->default('pending')->index();
            $table->string('phone', 40)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name')->nullable();
            $table->string('signer_business_number', 40)->nullable();
            $table->string('signer_address')->nullable();
            $table->string('signature_path')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_signatures');
    }
};
