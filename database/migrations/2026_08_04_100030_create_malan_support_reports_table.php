<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('malan_support_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chatbot_instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_chatbot_conversations')->nullOnDelete();
            $table->string('external_customer_id');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone_masked')->nullable();
            $table->string('issue_type', 50);
            $table->text('summary');
            $table->string('status', 30)->default('OPEN');
            $table->string('source_channel', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['chatbot_instance_id', 'conversation_id'], 'malan_support_inst_conv_idx');
            $table->index(
                ['chatbot_instance_id', 'external_customer_id', 'status'],
                'malan_support_inst_customer_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('malan_support_reports');
    }
};
