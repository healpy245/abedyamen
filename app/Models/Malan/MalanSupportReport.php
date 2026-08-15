<?php

declare(strict_types=1);

namespace App\Models\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MalanSupportReport extends Model
{
    protected $table = 'malan_support_reports';

    protected $fillable = [
        'chatbot_instance_id',
        'conversation_id',
        'external_customer_id',
        'customer_name',
        'customer_phone_masked',
        'issue_type',
        'summary',
        'status',
        'source_channel',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
}
