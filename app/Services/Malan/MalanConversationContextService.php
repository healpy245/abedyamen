<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotInstance;
use Carbon\Carbon;

class MalanConversationContextService
{
    public function getOrCreate(ChatbotConversation $conversation, ChatbotInstance $instance): ChatbotConversationContext
    {
        return ChatbotConversationContext::query()->firstOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'chatbot_instance_id' => $instance->id,
                'context' => [],
            ],
        );
    }

    public function getActive(ChatbotConversation $conversation): ?ChatbotConversationContext
    {
        $context = ChatbotConversationContext::query()
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($context === null || $context->isExpired()) {
            return null;
        }

        return $context;
    }

    public function storeLookupResult(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        MalanCustomerLookupResult $result,
        ?string $pendingFlow = 'internet_outage',
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $ttlHours = (int) config('malan.verified_context_ttl_hours', 24);

        $extra = is_array($context->context) ? $context->context : [];
        $extra['last_lookup_at'] = now()->toIso8601String();
        $extra['raw_status'] = $result->meta['raw_status'] ?? ($result->customer['status'] ?? null);

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => $result->customer['id'] ?? null,
            'verified_customer_name' => $result->customer['name'] ?? null,
            'verified_phone_masked' => $result->customer['phone_masked'] ?? null,
            'verified_identity_masked' => $result->customer['identity_masked'] ?? null,
            'customer_status' => $result->customer['status'] ?? null,
            'debt_amount' => $result->financial['debt_amount'] ?? null,
            'pending_flow' => $pendingFlow,
            'context' => $extra,
            'expires_at' => Carbon::now()->addHours($ttlHours),
        ]);
        $context->save();

        return $context->fresh();
    }

    public function setPaymentMethod(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $method,
        ?string $pendingFlow = null,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $context->payment_method = $method;
        if ($pendingFlow !== null) {
            $context->pending_flow = $pendingFlow;
        }
        $context->save();

        return $context->fresh();
    }

    public function setPendingFlow(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        ?string $flow,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $context->pending_flow = $flow;
        $context->save();

        return $context->fresh();
    }

    /**
     * Safe summary injected into the model context (never trust AI memory alone).
     *
     * @return array<string, mixed>|null
     */
    public function toPromptSummary(?ChatbotConversationContext $context): ?array
    {
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return null;
        }

        return [
            'verified_customer_id' => $context->verified_customer_id,
            'verified_customer_name' => $context->verified_customer_name,
            'verified_phone_masked' => $context->verified_phone_masked,
            'verified_identity_masked' => $context->verified_identity_masked,
            'customer_status' => $context->customer_status,
            'debt_amount' => $context->debt_amount !== null ? (float) $context->debt_amount : null,
            'pending_flow' => $context->pending_flow,
            'payment_method' => $context->payment_method,
            'bank' => [
                'name' => config('malan.bank.name'),
                'branch' => config('malan.bank.branch'),
                'account' => config('malan.bank.account'),
            ],
        ];
    }
}
