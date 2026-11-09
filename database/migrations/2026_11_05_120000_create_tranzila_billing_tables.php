<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing through Tranzila. finance_clients stays the customer: it gains the card token, which is the only
 * card credential ever stored here. Full card numbers and CVVs stay inside Tranzila's Hosted Fields.
 *
 * Transactions and documents are financial records, so they refuse to let their client be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->text('tranzila_token')->nullable()->after('card_holder');
            $table->string('billing_email')->nullable()->after('phone');
        });

        Schema::create('tranzila_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->restrictOnDelete();
            $table->foreignId('refund_of_id')->nullable()->constrained('tranzila_transactions')->restrictOnDelete();
            $table->foreignId('finance_payment_id')->nullable()->constrained('finance_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // One form submission can only ever become one charge.
            $table->string('idempotency_key', 64)->unique();
            $table->string('terminal', 32);
            $table->string('type', 10);
            $table->string('status', 10);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('ILS');
            $table->json('items');
            // Encrypted. A refund has to name the card that was charged, even after the client's card changes.
            $table->text('card_token')->nullable();
            $table->unsignedTinyInteger('expire_month')->nullable();
            $table->unsignedSmallInteger('expire_year')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_type_name', 40)->nullable();
            $table->unsignedBigInteger('tranzila_transaction_id')->nullable()->index();
            $table->string('auth_number', 32)->nullable();
            $table->string('processor_response_code', 10)->nullable();
            $table->integer('error_code')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->date('period')->nullable();
            $table->timestamps();

            $table->index(['finance_client_id', 'status']);
        });

        Schema::create('tranzila_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('tranzila_transactions')->restrictOnDelete();
            $table->foreignId('related_document_id')->nullable()->constrained('tranzila_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('terminal', 32);
            $table->string('document_type', 2);
            $table->unsignedTinyInteger('action')->default(1);
            $table->string('status', 10);
            $table->date('document_date');
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('ILS');
            // What is sent to Tranzila, so a failed document can be issued again unchanged.
            $table->json('payload');
            $table->string('tranzila_document_id', 40)->nullable();
            $table->string('number', 40)->nullable();
            $table->string('assignment_number', 40)->nullable();
            $table->string('retrieval_key', 255)->nullable();
            $table->string('enquiry_key', 40)->nullable();
            $table->integer('error_code')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['finance_client_id', 'status']);
        });

        Schema::create('tranzila_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('terminal', 32);
            $table->unsignedBigInteger('sto_id')->nullable()->index();
            $table->string('status', 10);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('ILS');
            $table->json('items');
            $table->string('charge_frequency', 12);
            $table->unsignedTinyInteger('charge_dom');
            $table->unsignedInteger('payments_number');
            $table->date('first_charge_date')->nullable();
            $table->integer('error_code')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tranzila_subscriptions');
        Schema::dropIfExists('tranzila_documents');
        Schema::dropIfExists('tranzila_transactions');

        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->dropColumn(['tranzila_token', 'billing_email']);
        });
    }
};
