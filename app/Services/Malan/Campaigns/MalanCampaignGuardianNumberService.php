<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Models\Malan\MalanCampaignGuardianNumber;
use App\Services\Malan\MalanPhoneNormalizer;

/**
 * Other decision-maker phones (husband / son / parent) collected during a campaign.
 * These are analytics-only — never stored as Malan CRM leads.
 */
class MalanCampaignGuardianNumberService
{
    public function __construct(
        protected MalanPhoneNormalizer $phoneNormalizer,
    ) {}

    public function store(
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
        string $phone,
    ): ?MalanCampaignGuardianNumber {
        $normalized = $this->phoneNormalizer->normalize($phone);
        $phoneNormalized = ($normalized['valid'] ?? false) === true && is_string($normalized['normalized'] ?? null)
            ? (string) $normalized['normalized']
            : (preg_replace('/\D+/', '', $phone) ?: '');

        if ($phoneNormalized === '' || strlen($phoneNormalized) < 8) {
            return null;
        }

        $contact = MalanCampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        return MalanCampaignGuardianNumber::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'phone_normalized' => $phoneNormalized,
            ],
            [
                'conversation_id' => $conversation->id,
                'source_contact_id' => $contact?->id,
                'phone' => $phone,
                'source_name' => $contact?->name,
                'source_phone' => $contact?->phone_normalized ?: $contact?->phone,
            ],
        );
    }
}
