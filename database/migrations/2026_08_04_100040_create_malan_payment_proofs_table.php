<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('malan_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chatbot_instance_id')->constrained('ai_chatbot_instances')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ai_chatbot_conversations')->cascadeOnDelete();
            $table->string('external_customer_id')->nullable();
            $table->string('payment_method', 40)->default('bank_transfer');
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('detected_amount', 12, 2)->nullable();
            $table->date('detected_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->string('verification_status', 40);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('verification_details')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('greenapi_message_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['chatbot_instance_id', 'greenapi_message_id'],
                'malan_proofs_inst_greenapi_uidx'
            );
            $table->index(
                ['conversation_id', 'verification_status'],
                'malan_proofs_conv_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('malan_payment_proofs');
    }
};
