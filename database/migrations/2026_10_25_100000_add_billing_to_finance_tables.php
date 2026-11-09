<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('mfit_client_id')->nullable()->after('company_id');
            $table->string('contact_name', 120)->nullable()->after('name');
            $table->string('business_number', 20)->nullable()->after('contact_name');
            $table->date('next_billing_date')->nullable()->after('monthly_fee');
            $table->json('billing_items')->nullable()->after('next_billing_date');
        });

        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->string('method', 20)->nullable()->after('status');
            $table->text('note')->nullable()->after('reference');
            $table->json('items')->nullable()->after('note');
        });

        Schema::create('finance_payment_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_payment_id')->constrained('finance_payments')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_files');

        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->dropColumn(['method', 'note', 'items']);
        });

        Schema::table('finance_clients', function (Blueprint $table): void {
            $table->dropColumn(['mfit_client_id', 'contact_name', 'business_number', 'next_billing_date', 'billing_items']);
        });
    }
};
