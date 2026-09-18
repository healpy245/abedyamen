<?php

declare(strict_types=1);

namespace App\Models\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MalanCampaignGuardianNumber extends Model
{
    protected $table = 'malan_campaign_guardian_numbers';

    protected $fillable = [
        'campaign_id',
        'conversation_id',
        'source_contact_id',
        'phone',
        'phone_normalized',
        'source_name',
        'source_phone',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MalanCampaign::class, 'campaign_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function sourceContact(): BelongsTo
    {
        return $this->belongsTo(MalanCampaignContact::class, 'source_contact_id');
    }
}
