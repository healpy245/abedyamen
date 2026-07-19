<?php

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotMember extends Model
{
    use HasFactory;

    protected $table = 'ai_chatbot_members';

    protected $fillable = [
        'instance_id',
        'name',
        'national_id',
        'phone',
        'customer_type',
        'payment_last4',
        'router_type',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_type' => 'string',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }
}
