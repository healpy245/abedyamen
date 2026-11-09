<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('company_id', 64)->unique();
            $table->string('name');
            $table->string('subdomain')->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('lang', 2)->default('he');
            $table->string('service_status', 20)->default('active');
            $table->string('payment_status', 20)->default('active');
            $table->boolean('has_payment_method')->default(false);
            $table->string('hardware_status', 20)->default('none');
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('failed_notice_at')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->cascadeOnDelete();
            $table->date('period');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('paid');
            $table->date('paid_on')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['finance_client_id', 'period']);
        });

        Schema::create('storage_items', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('name');
            $table->string('category', 20)->default('hardware');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->integer('stock')->default(0);
            $table->integer('low_stock')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('finance_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedInteger('number');
            $table->date('issued_on');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['type', 'number']);
        });

        Schema::create('finance_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->foreignId('storage_item_id')->nullable()->constrained('storage_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('storage_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_item_id')->constrained('storage_items')->cascadeOnDelete();
            $table->integer('change');
            $table->string('reason', 20);
            $table->foreignId('finance_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_movements');
        Schema::dropIfExists('finance_invoice_items');
        Schema::dropIfExists('finance_invoices');
        Schema::dropIfExists('storage_items');
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('finance_clients');
    }
};
