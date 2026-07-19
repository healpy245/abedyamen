<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMember;
use App\Models\User;

class AiChatbotMemberService
{
    public function __construct(
        protected AiChatbotInstanceService $instanceService,
    ) {
    }

    public function ensureEnabledForInstance(ChatbotInstance $instance, User $user): void
    {
        $this->instanceService->authorizeForUser($instance, $user);

        if (!$instance->storesMembers()) {
            abort(404);
        }
    }

    public function createForInstance(ChatbotInstance $instance, array $data): ChatbotMember
    {
        return ChatbotInstance::query()
            ->whereKey($instance->id)
            ->firstOrFail()
            ->members()
            ->create([
                'name' => $data['name'] ?? null,
                'national_id' => $data['national_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'customer_type' => $data['customer_type'] ?? 'new',
                'payment_last4' => $data['payment_last4'] ?? null,
                'router_type' => $data['router_type'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
    }
}
