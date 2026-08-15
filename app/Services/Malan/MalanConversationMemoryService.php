<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\ChatbotToolExecution;

/**
 * Per-conversation authoritative memory that does not rely on the model alone.
 */
class MalanConversationMemoryService
{
    public function __construct(
        protected MalanConversationContextService $contextService,
    ) {
    }

    public function rememberForConversation(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ?ChatbotConversationContext {
        if (! $instance->hasMalanIntegration()) {
            return null;
        }

        if ((int) $conversation->instance_id !== (int) $instance->id) {
            return null;
        }

        $context = $this->contextService->getActive($conversation)
            ?? $this->restoreFromToolHistory($conversation, $instance);

        if ($context === null) {
            return null;
        }

        return $this->syncPaymentIntentFromRecentMessages($conversation, $instance, $context);
    }

    /**
     * Update memory from an inbound user text before the model replies.
     */
    public function observeUserMessage(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $text,
    ): ?ChatbotConversationContext {
        $context = $this->rememberForConversation($conversation, $instance);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return $context;
        }

        if ($context->customer_status !== 'DEBT_DISCONNECTED' || $context->debt_amount === null) {
            return $context;
        }

        $method = $this->detectPaymentMethodChoice($text);
        if ($method === null) {
            return $context;
        }

        $pendingFlow = match ($method) {
            'bank_transfer' => 'awaiting_bank_transfer_proof',
            'visa_saved' => 'visa_saved_pending',
            'visa_other' => 'visa_other_pending',
            default => $context->pending_flow,
        };

        return $this->contextService->setPaymentMethod(
            $conversation,
            $instance,
            $method,
            $pendingFlow,
        );
    }

    /**
     * Update memory from an assistant reply (e.g. after it asked for a transfer photo).
     */
    public function observeAssistantMessage(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $text,
    ): ?ChatbotConversationContext {
        $context = $this->rememberForConversation($conversation, $instance);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return $context;
        }

        if ($this->assistantAskedForBankProof($text)) {
            return $this->contextService->setPaymentMethod(
                $conversation,
                $instance,
                'bank_transfer',
                'awaiting_bank_transfer_proof',
            );
        }

        return $context;
    }

    public function ensureAwaitingBankTransferProof(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ?ChatbotConversationContext {
        $context = $this->rememberForConversation($conversation, $instance);
        if ($context === null || ! $context->hasVerifiedCustomer()) {
            return $context;
        }

        if (
            $context->pending_flow === 'awaiting_bank_transfer_proof'
            && $context->payment_method === 'bank_transfer'
        ) {
            return $context;
        }

        if (
            $context->customer_status === 'DEBT_DISCONNECTED'
            && $context->debt_amount !== null
            && ! in_array((string) $context->pending_flow, [
                'support_report_open',
                'payment_proof_verified_pending_reactivation',
            ], true)
        ) {
            return $this->contextService->setPaymentMethod(
                $conversation,
                $instance,
                'bank_transfer',
                'awaiting_bank_transfer_proof',
            );
        }

        if ($this->recentAssistantAskedForBankProof($conversation)) {
            return $this->contextService->setPaymentMethod(
                $conversation,
                $instance,
                'bank_transfer',
                'awaiting_bank_transfer_proof',
            );
        }

        return $context;
    }

    private function restoreFromToolHistory(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ?ChatbotConversationContext {
        $lookup = ChatbotToolExecution::query()
            ->where('conversation_id', $conversation->id)
            ->where('chatbot_instance_id', $instance->id)
            ->where('tool_name', 'lookup_malan_customer')
            ->where('success', true)
            ->latest('id')
            ->first();

        if ($lookup === null) {
            return null;
        }

        $result = is_array($lookup->result) ? $lookup->result : [];
        if (! ($result['found'] ?? false) || ! is_array($result['customer'] ?? null)) {
            return null;
        }

        $dto = new \App\Data\Malan\MalanCustomerLookupResult(
            success: true,
            found: true,
            customer: [
                'id' => (string) ($result['customer']['id'] ?? ''),
                'name' => $result['customer']['name'] ?? null,
                'phone_masked' => $result['customer']['phone_masked'] ?? null,
                'identity_masked' => $result['customer']['identity_masked'] ?? null,
                'status' => $result['customer']['status'] ?? 'UNKNOWN',
                'city' => $result['customer']['city'] ?? null,
            ],
            financial: [
                'balance_raw' => isset($result['financial']['balance_raw']) ? (float) $result['financial']['balance_raw'] : null,
                'debt_amount' => isset($result['financial']['debt_amount']) ? (float) $result['financial']['debt_amount'] : null,
                'currency' => (string) ($result['financial']['currency'] ?? 'ILS'),
            ],
            meta: ['restored_from_tool_execution' => $lookup->id],
        );

        if (($dto->customer['id'] ?? '') === '') {
            return null;
        }

        return $this->contextService->storeLookupResult(
            $conversation,
            $instance,
            $dto,
            'internet_outage',
        );
    }

    private function syncPaymentIntentFromRecentMessages(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        ChatbotConversationContext $context,
    ): ChatbotConversationContext {
        if ($context->payment_method === 'bank_transfer'
            && $context->pending_flow === 'awaiting_bank_transfer_proof'
        ) {
            return $context;
        }

        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        foreach ($recent as $message) {
            if ($message->role === 'user') {
                $method = $this->detectPaymentMethodChoice((string) $message->message);
                if ($method === 'bank_transfer'
                    && $context->customer_status === 'DEBT_DISCONNECTED'
                    && $context->debt_amount !== null
                ) {
                    return $this->contextService->setPaymentMethod(
                        $conversation,
                        $instance,
                        'bank_transfer',
                        'awaiting_bank_transfer_proof',
                    );
                }
            }

            if ($message->role === 'assistant' && $this->assistantAskedForBankProof((string) $message->message)) {
                return $this->contextService->setPaymentMethod(
                    $conversation,
                    $instance,
                    'bank_transfer',
                    'awaiting_bank_transfer_proof',
                );
            }
        }

        return $context;
    }

    private function recentAssistantAskedForBankProof(ChatbotConversation $conversation): bool
    {
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('message');

        foreach ($messages as $message) {
            if ($this->assistantAskedForBankProof((string) $message)) {
                return true;
            }
        }

        return false;
    }

    private function assistantAskedForBankProof(string $text): bool
    {
        $normalized = mb_strtolower($text);

        $hasProofAsk = str_contains($normalized, 'אסמכתה')
            || (str_contains($normalized, 'صورة') && (str_contains($normalized, 'تحويل') || str_contains($normalized, 'التحويل')))
            || str_contains($normalized, 'صورة واضحة');

        $hasBankDetails = str_contains($text, '603495')
            || str_contains($text, '665')
            || str_contains($normalized, 'הפועלים')
            || str_contains($normalized, 'تحويل بنكي');

        return ($hasProofAsk && $hasBankDetails)
            || (str_contains($normalized, 'אסמכתה') && str_contains($text, '318'))
            || (str_contains($normalized, 'صورة واضحة') && str_contains($normalized, 'تحويل'));
    }

    private function detectPaymentMethodChoice(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/\b(بنكي|تحويل|העברה|bank\s*transfer)\b/u', $normalized)
            || $normalized === 'بنكي'
            || str_contains($normalized, 'تحويل بنكي')
        ) {
            // Prefer bank if both mentioned; explicit visa wins below.
            if (! preg_match('/\b(فيزا|visa|بطاقة)\b/u', $normalized)) {
                return 'bank_transfer';
            }
        }

        if (preg_match('/بطاقة\s*مسجل|saved\s*card|البطاقة المسجلة/u', $normalized)) {
            return 'visa_saved';
        }

        if (preg_match('/بطاقة\s*ثاني|بطاقة\s*أخرى|بطاقة ثانية|another\s*card/u', $normalized)) {
            return 'visa_other';
        }

        if (preg_match('/\b(فيزا|visa)\b/u', $normalized) && ! str_contains($normalized, 'تحويل')) {
            return 'visa_saved';
        }

        if ($normalized === 'بنكي' || str_starts_with($normalized, 'بنكي')) {
            return 'bank_transfer';
        }

        return null;
    }
}
