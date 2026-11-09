<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents no longer close sales themselves: they send a close-deal request with payment evidence,
     * and MFITGROUP Finance confirms it (which starts billing) or returns it with a note.
     */
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->timestamp('whatsapp_sent_at')->nullable()->after('notes');
            $table->text('deal_note')->nullable()->after('whatsapp_sent_at');
            $table->timestamp('requested_at')->nullable()->after('deal_note');
            $table->text('finance_note')->nullable()->after('requested_at');
            $table->foreignId('confirmed_by')->nullable()->after('finance_note')->constrained('users')->nullOnDelete();
        });

        Schema::create('sales_quote_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quote_id')->constrained('sales_quotes')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        // Old manual statuses fold into the new flow.
        DB::table('sales_quotes')->where('status', 'approved')->update(['status' => 'sent']);
        DB::table('sales_quotes')->whereIn('status', ['rejected', 'archived'])->update(['status' => 'draft']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_files');

        Schema::table('sales_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['whatsapp_sent_at', 'deal_note', 'requested_at', 'finance_note']);
        });
    }
};
