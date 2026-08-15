<?php

declare(strict_types=1);

namespace App\Models\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MalanPaymentProof extends Model
{
    protected $table = 'malan_payment_proofs';

    protected $fillable = [
        'chatbot_instance_id',
        'conversation_id',
        'external_customer_id',
        'payment_method',
        'expected_amount',
        'detected_amount',
        'detected_date',
        'reference_number',
        'file_path',
        'mime_type',
        'verification_status',
        'confidence',
        'verification_details',
        'reviewed_by',
        'reviewed_at',
        'greenapi_message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'detected_amount' => 'decimal:2',
            'detected_date' => 'date',
            'confidence' => 'float',
            'verification_details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'chatbot_instance_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
