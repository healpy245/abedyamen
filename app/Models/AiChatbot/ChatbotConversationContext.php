<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotConversationContext extends Model
{
    protected $table = 'ai_chatbot_conversation_contexts';

    protected $fillable = [
        'conversation_id',
        'chatbot_instance_id',
        'verified_customer_id',
        'verified_customer_name',
        'verified_phone_masked',
        'verified_identity_masked',
        'customer_status',
        'debt_amount',
        'pending_flow',
        'payment_method',
        'context',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debt_amount' => 'decimal:2',
            'context' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'chatbot_instance_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasVerifiedCustomer(): bool
    {
        return ! $this->isExpired()
            && is_string($this->verified_customer_id)
            && $this->verified_customer_id !== '';
    }
}
