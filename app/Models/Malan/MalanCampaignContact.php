<?php

declare(strict_types=1);

namespace App\Models\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MalanCampaignContact extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_LEAD_CREATED = 'lead_created';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'malan_campaign_contacts';

    protected $fillable = [
        'campaign_id',
        'name',
        'phone',
        'phone_normalized',
        'city',
        'chat_id',
        'status',
        'conversation_id',
        'message_sent_at',
        'responded_at',
        'lead_created_at',
        'malan_lead_id',
        'last_error',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'lead_created_at' => 'datetime',
            'malan_lead_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MalanCampaign::class, 'campaign_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function isPendingSend(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_FAILED], true)
            && $this->message_sent_at === null;
    }
}
