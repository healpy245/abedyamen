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

        if ($conversation->isCampaignLeadBot()) {
            $existing = $this->contextService->getActive($conversation);
            if ($this->contextService->isCampaignLeadCollectionMode($existing)) {
                return $existing;
            }

            return $this->contextService->beginCampaignSales($conversation, $instance);
        }

        $context = $this->contextService->getActive($conversation);

        // Never resurrect a prior verified customer while this chat is in new-signup mode.
        if ($context === null || ! $this->contextService->isNewSignupMode($context)) {
            $context = $context ?? $this->restoreFromToolHistory($conversation, $instance);
        }

        $context = $this->syncIntentFromRecentMessages($conversation, $instance, $context);

        if ($context === null) {
            return null;
        }

        if ($this->contextService->isNewSignupMode($context) || ! $context->hasVerifiedCustomer()) {
            return $context;
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
        // Campaign outreach stays in sales mode until clear buying / next-step intent.
        if ($conversation->isCampaignLeadBot()) {
            $existing = $this->contextService->getActive($conversation);
            $alreadySentCallback = $this->assistantRecentlyOfferedCallback($conversation);

            if ($this->lastAssistantAskedFiberAtHome($conversation)) {
                if ($this->looksLikeNoFiberAtHome($text) || $this->looksLikeBareNo($text)) {
                    $this->contextService->rememberCampaignFiber($conversation, $instance, false);
                } elseif ($this->looksLikeHasFiberAtHome($text)
                    || $this->looksLikeBareYes($text)
                    || $this->looksLikeJoinAffirmative($text)
                ) {
                    $this->contextService->rememberCampaignFiber($conversation, $instance, true);
                }
            }

            if ($this->looksLikeCompetitorLoyalty($text) || $this->looksLikeSoftStayObjection($text)) {
                return $this->contextService->resumeCampaignSalesForQuestions($conversation, $instance);
            }

            if ($this->assistantRecentlyAskedClosingQuestion($conversation)
                && $this->looksLikeJoinAffirmative($text)
            ) {
                return $this->contextService->beginCampaignLeadCollection($conversation, $instance);
            }

            if ($this->looksLikeWantsToKeepTalking($text)) {
                return $this->contextService->resumeCampaignSalesForQuestions($conversation, $instance);
            }

            if ($this->looksLikeHardCampaignOptOut($text)
                || ($this->assistantRecentlySentGiveChance($conversation)
                    && ($this->looksLikeSoftRefusal($text) || $this->looksLikeBareNo($text)))
            ) {
                return $this->contextService->markCampaignOptedOut($conversation, $instance);
            }

            if ($this->looksLikeCampaignOptOut($text)
                || ($alreadySentCallback && $this->looksLikeBareNo($text))
            ) {
                if ($alreadySentCallback || $this->assistantRecentlySentGiveChance($conversation)) {
                    return $this->contextService->markCampaignOptedOut($conversation, $instance);
                }

                return $this->contextService->beginCampaignSales($conversation, $instance);
            }

            if ($this->contextService->isCampaignOptedOut($existing)) {
                return $existing;
            }

            // Soft handoff may happen while still in sales mode (e.g. voice transcript).
            // Affirmative after "عهاذ/عهاد الرقم؟" must lock the number and move to name — never re-ask.
            if ($this->assistantRecentlyAskedSameNumber($conversation)
                || $this->assistantRecentlyAskedForFullName($conversation)
            ) {
                $writtenPhone = $this->extractWrittenCampaignPhone($text);
                if ($writtenPhone !== null) {
                    return $this->contextService->rememberCampaignLeadPhone($conversation, $instance, $writtenPhone);
                }
            }

            if ($this->assistantRecentlyAskedSameNumber($conversation)
                && $this->looksLikeSameNumberAffirmative($text)
            ) {
                $this->contextService->beginCampaignLeadCollection($conversation, $instance);

                return $this->contextService->markContactOnCurrentNumber($conversation, $instance);
            }

            if ($this->looksLikeCampaignConversionIntent($text)) {
                return $this->contextService->beginCampaignLeadCollection($conversation, $instance);
            }

            if ($this->contextService->isCampaignLeadCollectionMode($existing)) {
                return $existing;
            }

            return $this->contextService->beginCampaignSales($conversation, $instance);
        }

        if ($this->looksLikeOutageOrExistingAccountIntent($text)) {
            return $this->contextService->beginExistingSupport($conversation, $instance, 'internet_outage');
        }

        if ($this->looksLikeNewSignupIntent($text)) {
            return $this->contextService->beginNewSignup($conversation, $instance);
        }

        $context = $this->rememberForConversation($conversation, $instance);

        if ($context === null || ! $context->hasVerifiedCustomer()) {
            if ($this->recentLooksLikeNewSignup($conversation)) {
                return $this->contextService->beginNewSignup($conversation, $instance);
            }

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
        if ($conversation->isCampaignLeadBot()) {
            $existing = $this->contextService->getActive($conversation);

            if ($this->assistantMessageLooksLikeCallbackOffer($text)) {
                $existing = $this->contextService->markCampaignCallbackOffered($conversation, $instance);
            }

            if ($this->contextService->isCampaignLeadCollectionMode($existing)) {
                return $existing;
            }

            // Asking the same-number soft handoff means we left pure sales chatter.
            if ($this->assistantMessageAsksSameNumber($text)) {
                return $this->contextService->beginCampaignLeadCollection($conversation, $instance);
            }

            return $this->contextService->beginCampaignSales($conversation, $instance);
        }

        if ($this->assistantAskedForExistingAccountLookup($text)) {
            return $this->contextService->beginExistingSupport($conversation, $instance, 'internet_outage');
        }

        if ($this->assistantAskedForNewLeadDetails($text)) {
            return $this->contextService->beginNewSignup($conversation, $instance);
        }

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

    private function syncIntentFromRecentMessages(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        ?ChatbotConversationContext $context,
    ): ?ChatbotConversationContext {
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['role', 'message']);

        foreach ($messages as $message) {
            $text = (string) $message->message;
            if ($message->role !== 'user') {
                if ($message->role === 'assistant' && $this->assistantAskedForExistingAccountLookup($text)) {
                    if ($context !== null && ! $this->contextService->isNewSignupMode($context)) {
                        return $context;
                    }

                    return $this->contextService->beginExistingSupport($conversation, $instance, 'internet_outage');
                }
                if ($message->role === 'assistant' && $this->assistantAskedForNewLeadDetails($text)) {
                    if ($this->contextService->isNewSignupMode($context)) {
                        return $context;
                    }

                    return $this->contextService->beginNewSignup($conversation, $instance);
                }

                continue;
            }

            if ($this->looksLikeOutageOrExistingAccountIntent($text)) {
                if ($context !== null && ! $this->contextService->isNewSignupMode($context)) {
                    return $context;
                }

                return $this->contextService->beginExistingSupport($conversation, $instance, 'internet_outage');
            }

            if ($this->looksLikeNewSignupIntent($text)) {
                if ($this->contextService->isNewSignupMode($context) && ! ($context?->hasVerifiedCustomer() ?? false)) {
                    return $context ?? $this->contextService->beginNewSignup($conversation, $instance);
                }

                return $this->contextService->beginNewSignup($conversation, $instance);
            }

            // First decisive user message wins; stop after first user turn that isn't noise.
            break;
        }

        return $context;
    }

    private function recentLooksLikeNewSignup(ChatbotConversation $conversation): bool
    {
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['role', 'message']);

        foreach ($messages as $message) {
            $text = (string) $message->message;
            if ($message->role === 'user' && $this->looksLikeOutageOrExistingAccountIntent($text)) {
                return false;
            }
            if ($message->role === 'user' && $this->looksLikeNewSignupIntent($text)) {
                return true;
            }
            if ($message->role === 'assistant' && $this->assistantAskedForNewLeadDetails($text)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeOutageOrExistingAccountIntent(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/(مقطوع|قاطع|انقطع|تقطيع|مشكله|مشكلة|بلانترنت|بالانترنت|بالنت|النت\s*فاصل|ما في\s*نت|دين|ניתוק|حسابي|رقمي المسجل|هويتي|فحص\s*الحساب|انترنت\s*(عندي|فاصل|خربان)|خربان)/u',
            $normalized,
        );
    }

    private function looksLikeNewSignupIntent(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeOutageOrExistingAccountIntent($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/(بدي\s*(اركب\s*)?(نت|انترنت)|أريد\s*انترنت|اشترك|اشتراك(\s*جديد)?|باقة|تسجيل\s*جديد|1000\s*ميجا|اركب\s*انترنت|بدار\s+)/u',
            $normalized,
        );
    }

    /**
     * Clear buying / conversion intent for campaign sales → lead collection.
     */
    private function looksLikeCampaignConversionIntent(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeOutageOrExistingAccountIntent($normalized)) {
            return false;
        }

        if ($this->looksLikeCampaignOptOut($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'بدي\s*(اشترك|اشتراك|اركب|نت|انترنت)|'.
            'اشترك|اشتراك|سجّ?لوني|سجلوني|'.
            'كيف\s*(بقدر|ممكن)?\s*(اشترك|أسجل|اسجل|انضم)|'.
            'وين\s*(بقدر|ممكن)?\s*(اشترك|أسجل)|'.
            'شو\s*(اعمل|أعمل|المطلوب|الخطوة)|'.
            'وانا\s*شو\s*(اعمل|أعمل)|'.
            'طيب\s*وبعدين|وبعدين\s*\؟?|'.
            'كيف\s*بنضم|'.
            'تفحص(وا|ي)?\s*(التغطية|التغطي)|'.
            'في\s*تغطية|'.
            'interested|subscribe|sign\s*up|'.
            'אבדוק|רוצה\s*להצטרף|איך\s*נרשמים'.
            ')/u',
            $normalized,
        );
    }

    public function looksLikeCampaignOptOut(string $text): bool
    {
        return $this->looksLikeHardCampaignOptOut($text) || $this->looksLikeSoftRefusal($text);
    }

    public function looksLikeHardCampaignOptOut(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'حلي\s*عني|خلي\s*عني|'.
            'لا\s*تبعث|لا\s*تتواصل|'.
            'اتركوني|اتركني|'.
            'بطل(وا|ي)?\s*(تراسل|تبعث|تحكي)|'.
            '^خلص$|خلص\s*باي|'.
            '\bباي\b|مع\s*السلام|'.
            'stop|unsubscribe'.
            ')/u',
            $normalized,
        );
    }

    public function looksLikeSoftRefusal(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeHardCampaignOptOut($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'مش\s*مهتم|مش\s*حابب|'.
            'ما\s*بدي|مابديش|بديش|'.
            'بلزمش|لا\s*بلزم|مش\s*ناقص|'.
            'not\s*interested'.
            ')/u',
            $normalized,
        );
    }

    public function looksLikeCompetitorLoyalty(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'بيزك|בזק|bezeq|'.
            'هوت|הוט|\bhot\b|'.
            'بارتنر|פרטנר|partner|'.
            'سلكوم|סלקום|cellcom|'.
            '012|wecom|ويكم'.
            ')/u',
            $normalized,
        );
    }

    public function looksLikeSoftStayObjection(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeHardCampaignOptOut($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'مرتاح|مرتاخ|مبسوط\s*مع|'.
            'ما\s*بدي\s*اغير|ما\s*بغير|'.
            'بضل\s*على|بضل\s*مع'.
            ')/u',
            $normalized,
        ) || $this->looksLikeCompetitorLoyalty($normalized);
    }

    public function looksLikeBareYes(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);

        return in_array($normalized, ['اه', 'اها', 'ايوه', 'ايه', 'نعم', 'yes', 'yep', 'في', 'عنا', 'موجود'], true)
            || (bool) preg_match('/^(اه+|ايوه|نعم|في\s*عنا|عنا\s*فايبر|عنا\s*סיב)/u', $normalized);
    }

    /**
     * Yes to joining / the offer — including rhetorical «ليش لا» (why not = I will).
     */
    public function looksLikeJoinAffirmative(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeHardCampaignOptOut($normalized)
            || $this->looksLikeSoftRefusal($normalized)
            || $this->looksLikeCompetitorLoyalty($normalized)
            || $this->looksLikeSoftStayObjection($normalized)
            || $this->looksLikeBareNo($normalized)
        ) {
            return false;
        }

        if ((bool) preg_match('/(ليش\s*لا+|why\s*not|ما\s*في\s*مشكل|فش\s*مشكل)/u', $normalized)) {
            return true;
        }

        if (in_array($normalized, [
            'اه', 'اها', 'ايوه', 'ايه', 'نعم', 'yes', 'yep', 'يب',
            'يلا', 'اكيد', 'تمام', 'ماشي', 'بدي', 'اوك', 'ok', 'okay',
            'خلينا', 'انضم', 'طبعا', 'اوكي',
        ], true)) {
            return true;
        }

        return (bool) preg_match('/^(اه+|ايوه|يلا|اكيد|تمام|ماشي|اه\s*بدي|خلينا|طبعا)/u', $normalized);
    }

    public function looksLikeHasFiberAtHome(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeNoFiberAtHome($normalized)) {
            return false;
        }

        return $this->looksLikeBareYes($normalized)
            || (bool) preg_match('/(في\s*عنا|عنا\s*(فايبر|סיב)|ممدود|موجود|סיב\s*אופטי)/u', $normalized);
    }

    public function looksLikeNoFiberAtHome(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeJoinAffirmative($normalized)
            || (bool) preg_match('/ما\s*في\s*مشكل|فش\s*مشكل/u', $normalized)
        ) {
            return false;
        }

        return (bool) preg_match(
            '/(لسه|لسا|ما\s*في|مش\s*ممدود|ما\s*ممدود|بدون\s*فايبر|ما\s*عنا)/u',
            $normalized,
        );
    }

    /**
     * Whether the customer already has fiber at home (null = never answered).
     */
    public function inferCampaignHasFiber(ChatbotConversation $conversation): ?bool
    {
        $fromContext = $this->contextService->campaignHasFiber($this->contextService->getActive($conversation));
        if ($fromContext !== null) {
            return $fromContext;
        }

        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(500)
            ->get(['role', 'message']);

        $awaitingFiber = false;
        $result = null;

        foreach ($messages as $message) {
            $text = (string) $message->message;
            if ($message->role === 'assistant') {
                $awaitingFiber = str_contains($text, 'סיב אופטי بالبيت')
                    || str_contains($text, 'عندكم סיב');
                continue;
            }
            if ($message->role !== 'user' || ! $awaitingFiber) {
                continue;
            }

            if ($this->looksLikeNoFiberAtHome($text) || $this->looksLikeBareNo($text)) {
                $result = false;
            } elseif ($this->looksLikeHasFiberAtHome($text)
                || $this->looksLikeBareYes($text)
                || $this->looksLikeJoinAffirmative($text)
            ) {
                $result = true;
            }
            $awaitingFiber = false;
        }

        return $result;
    }

    /**
     * Sales-facing CRM note: later call / preferred time, fiber, alternate phone.
     */
    public function composeLeadNote(ChatbotConversation $conversation): ?string
    {
        $userTexts = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderBy('id')
            ->limit(500)
            ->pluck('message');

        $preferredTime = null;
        $wantsLater = false;
        foreach ($userTexts as $text) {
            $extracted = $this->extractPreferredCallTime((string) $text);
            if ($extracted !== null) {
                $preferredTime = $extracted;
            }
            if ($this->looksLikeWantsLaterCall((string) $text)) {
                $wantsLater = true;
            }
        }

        $lines = [];
        if ($preferredTime !== null) {
            $lines[] = 'يفضل مكالمة الساعة '.$preferredTime;
        } elseif ($wantsLater) {
            $lines[] = 'طلب يتواصلوا معه لاحقا / مش هلق';
        }

        $hasFiber = $this->inferCampaignHasFiber($conversation);
        if ($hasFiber === true) {
            $lines[] = 'عنده סיב אופטי بالبيت';
        } elseif ($hasFiber === false) {
            $lines[] = 'ما عنده סיב אופטי بالبيت';
        }

        $leadPhone = $this->contextService->campaignLeadPhone($this->contextService->getActive($conversation));
        if ($leadPhone !== null && ! $this->contextService->campaignContactOnCurrentNumber($this->contextService->getActive($conversation))) {
            $lines[] = 'رقم بديل للتواصل: '.$leadPhone;
        }

        if ($lines === []) {
            return null;
        }

        return implode("\n", $lines);
    }

    /**
     * Explicit clock time in the customer message (16:00, الساعة 4, 4 المسا…).
     */
    public function extractPreferredCallTime(string $text): ?string
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return null;
        }

        $normalized = strtr($normalized, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (preg_match('/\b(\d{1,2})[:\.](\d{2})\b/', $normalized, $match)) {
            return $this->formatCallClock((int) $match[1], (int) $match[2], $normalized);
        }

        if (preg_match('/(?:الساعة|الساعه|ساعه)\s*(\d{1,2})/u', $normalized, $match)) {
            return $this->formatCallClock((int) $match[1], 0, $normalized);
        }

        if (preg_match('/\b(\d{1,2})\s*(?:pm|مساء|المسا|العصر|بعد\s*الظهر)/u', $normalized, $match)) {
            return $this->formatCallClock((int) $match[1], 0, 'pm');
        }

        return null;
    }

    public function looksLikeWantsLaterCall(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->extractPreferredCallTime($normalized) !== null) {
            return true;
        }

        return (bool) preg_match(
            '/('.
            'لاحقا|لاحقًا|بكره|بكرا|غدا|غدًا|'.
            'مش\s*هلق|مو\s*هلق|مش\s*هسا|مو\s*هسا|'.
            'بوقت\s*ثاني|بوقت\s*تاني|بعد\s*الظهر|'.
            'رجع(وا|ولي|ي)|اتصل(وا|ي)?\s*(بعدين|لاحقا)|'.
            'تواصل(وا|ي)?\s*(بعدين|لاحقا)|'.
            'بعد\s*شوي|call\s*me\s*later'.
            ')/u',
            $normalized,
        );
    }

    private function formatCallClock(int $hour, int $minute, string $context): ?string
    {
        if ($minute < 0 || $minute > 59 || $hour < 0 || $hour > 23) {
            return null;
        }

        $pm = (bool) preg_match('/(pm|مساء|المسا|العصر|بعد\s*الظهر)/u', $context);
        if ($pm && $hour > 0 && $hour < 12) {
            $hour += 12;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Customer wants to keep chatting — not a refusal, even after a callback offer.
     */
    public function looksLikeWantsToKeepTalking(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeCampaignOptOut($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'اسمع|اشرح|شرح|فكره|فكرة|'.
            'دردش|دردشه|اسال|أسأل|سؤال|'.
            'فاضيه|فاضي|هسا|هلق|هلق|اسا|'.
            'هاد\s*الوقت|بهالوقت|هلق\s*فاضي'.
            ')/u',
            $normalized,
        ) || $this->looksLikeAsksAboutCustomerReviews($normalized);
    }

    /**
     * Asks how other customers feel — send the Instagram testimonials video.
     */
    public function looksLikeAsksAboutCustomerReviews(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeCampaignOptOut($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/('.
            '(راي|رأي|آراء|اراء).{0,40}(زباين|زبائن|لكوحوت|لكوحت|לקוחות)|'.
            '(زباين|زبائن|لكوحوت|لكوحت|לקוחות).{0,40}(راي|رأي|آراء|اراء|مبسوط)|'.
            'باقي.{0,20}(زباين|لكوحوت|لكوحت|לקוחות|الناس)|'.
            'مبسوطين|آراء\s*الزباين|اراء\s*الزباين|'.
            'فيديو.{0,20}(زباين|اراء|آراء)'.
            ')/u',
            $normalized,
        );
    }

    public function looksLikeBareNo(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);

        return in_array($normalized, ['لا', 'لأ', 'لاء', 'no', 'nope'], true);
    }

    /**
     * @return array{
     *     intro_asked: bool,
     *     company_pitch_sent: bool,
     *     closing_question_asked: bool,
     *     callback_offered: bool,
     *     do_not_repeat: list<string>
     * }
     */
    public function campaignTurnMemory(ChatbotConversation $conversation): array
    {
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['role', 'message']);

        $flags = [
            'intro_asked' => false,
            'company_pitch_sent' => false,
            'closing_question_asked' => false,
            'callback_offered' => false,
            'fiber_asked' => false,
            'persuasion_sent' => false,
            'give_chance_sent' => false,
            'social_proof_sent' => false,
        ];
        $recentAssistant = [];

        foreach ($messages as $message) {
            $text = (string) $message->message;
            if ($message->role !== 'assistant') {
                continue;
            }
            if (count($recentAssistant) < 24) {
                $recentAssistant[] = mb_substr($text, 0, 220);
            }
            if (str_contains($text, 'اكيد بتعرفنا')) {
                $flags['intro_asked'] = true;
            }
            if (str_contains($text, 'نجاح رهيب')) {
                $flags['company_pitch_sent'] = true;
            }
            if (str_contains($text, 'حابب تكون جزء من نجاحنا')) {
                $flags['closing_question_asked'] = true;
            }
            if ($this->assistantMessageLooksLikeCallbackOffer($text)) {
                $flags['callback_offered'] = true;
            }
            if (str_contains($text, 'סיב אופטי بالبيت')) {
                $flags['fiber_asked'] = true;
            }
            if ($this->assistantMessageLooksLikeCompetitorPersuasion($text)) {
                $flags['persuasion_sent'] = true;
            }
            if ($this->assistantMessageLooksLikeGiveChance($text)) {
                $flags['give_chance_sent'] = true;
            }
            if ($this->assistantMessageLooksLikeSocialProof($text)) {
                $flags['social_proof_sent'] = true;
            }
        }

        return [
            'intro_asked' => $flags['intro_asked'],
            'company_pitch_sent' => $flags['company_pitch_sent'],
            'closing_question_asked' => $flags['closing_question_asked'],
            'callback_offered' => $flags['callback_offered'],
            'fiber_asked' => $flags['fiber_asked'],
            'persuasion_sent' => $flags['persuasion_sent'],
            'give_chance_sent' => $flags['give_chance_sent'],
            'social_proof_sent' => $flags['social_proof_sent'],
            'do_not_repeat' => array_values(array_unique($recentAssistant)),
        ];
    }

    /**
     * Full-thread anchors so the model stays aware of the first and last turns
     * for this phone until a campaign trigger resend wipes the chat.
     *
     * @return array{
     *     phone: string,
     *     message_count: int,
     *     first_customer_message: string,
     *     last_customer_message: string,
     *     first_assistant_message: string,
     *     last_assistant_message: string,
     *     read_full_thread: true,
     *     reset_memory_only_on_campaign_trigger: true
     * }
     */
    public function campaignThreadSnapshot(ChatbotConversation $conversation): array
    {
        $messages = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['role', 'message']);

        $clip = static fn (string $text): string => mb_substr(trim($text), 0, 280);
        $firstUser = $lastUser = $firstAssistant = $lastAssistant = '';

        foreach ($messages as $message) {
            $text = $clip((string) $message->message);
            if ($text === '') {
                continue;
            }
            if ($message->role === 'user') {
                if ($firstUser === '') {
                    $firstUser = $text;
                }
                $lastUser = $text;
            } elseif ($message->role === 'assistant') {
                if ($firstAssistant === '') {
                    $firstAssistant = $text;
                }
                $lastAssistant = $text;
            }
        }

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $phone = trim((string) ($conversation->contact_phone ?? ''));
        if ($phone === '') {
            $phone = trim((string) ($meta['whatsapp_chat_id'] ?? $meta['whatsapp_chat_phone'] ?? ''));
        }

        return [
            'phone' => $phone,
            'message_count' => $messages->count(),
            'first_customer_message' => $firstUser,
            'last_customer_message' => $lastUser,
            'first_assistant_message' => $firstAssistant,
            'last_assistant_message' => $lastAssistant,
            'read_full_thread' => true,
            'reset_memory_only_on_campaign_trigger' => true,
        ];
    }

    public function assistantRecentlyOfferedCallback(ChatbotConversation $conversation): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('message');

        foreach ($recent as $message) {
            if ($this->assistantMessageLooksLikeCallbackOffer((string) $message)) {
                return true;
            }
        }

        return false;
    }

    public function assistantMessageLooksLikeCallbackOffer(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);

        return str_contains($normalized, 'نرجعلك بمكالمه')
            || str_contains($normalized, 'نرجعلك بمكالمة')
            || str_contains($normalized, 'بوقت ثاني');
    }

    public function assistantMessageLooksLikeCompetitorPersuasion(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);

        return str_contains($normalized, 'استغل السعر')
            || str_contains($normalized, 'من الالف للياء')
            || str_contains($normalized, 'وقت محدود')
            || str_contains($normalized, 'فاهمك انك مرتاح')
            || str_contains($normalized, 'فاهمك. استغل');
    }

    public function assistantMessageLooksLikeGiveChance(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);

        return str_contains($normalized, 'بقترح عليك')
            || str_contains($normalized, 'تعطي فرصه')
            || str_contains($normalized, 'تعطي فرصة');
    }

    public function assistantMessageLooksLikeSocialProof(string $text): bool
    {
        return str_contains($text, 'instagram.com/reel/DOs3eKXDNa7')
            || str_contains($text, 'اراء الزباين')
            || str_contains($this->normalizeCampaignUtterance($text), 'لتقديم حلول');
    }

    public function lastAssistantAskedFiberAtHome(ChatbotConversation $conversation): bool
    {
        $last = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->value('message');

        if (! is_string($last) || $last === '') {
            return false;
        }

        $normalized = $this->normalizeCampaignUtterance($last);

        return str_contains($last, 'סיב אופטי بالبيت')
            || str_contains($last, 'عندكم סיב')
            || str_contains($normalized, 'فايبر بالبيت')
            || str_contains($normalized, 'سيب اوبطي بالبيت');
    }

    public function assistantRecentlyAskedClosingQuestion(ChatbotConversation $conversation): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('message');

        foreach ($recent as $message) {
            if (str_contains((string) $message, 'حابب تكون جزء من نجاحنا')) {
                return true;
            }
        }

        return false;
    }

    public function assistantRecentlySentCompetitorPersuasion(ChatbotConversation $conversation): bool
    {
        return $this->recentAssistantMatches(
            $conversation,
            fn (string $text): bool => $this->assistantMessageLooksLikeCompetitorPersuasion($text),
        );
    }

    public function assistantRecentlySentGiveChance(ChatbotConversation $conversation): bool
    {
        return $this->recentAssistantMatches(
            $conversation,
            fn (string $text): bool => $this->assistantMessageLooksLikeGiveChance($text),
        );
    }

    /**
     * @param  callable(string): bool  $matcher
     */
    private function recentAssistantMatches(ChatbotConversation $conversation, callable $matcher): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message');

        foreach ($recent as $message) {
            if ($matcher((string) $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Israeli mobile written in the customer message (05XXXXXXXX / +9725…).
     */
    public function extractWrittenCampaignPhone(string $text): ?string
    {
        $normalizer = new MalanPhoneNormalizer;
        $direct = $normalizer->normalize($text);
        if (($direct['valid'] ?? false) === true && is_string($direct['normalized'] ?? null)) {
            return $direct['normalized'];
        }

        if (! preg_match_all('/(?:\+?972[\s\-]?|0)?5[\d\s\-]{7,14}/u', $text, $matches)) {
            return null;
        }

        foreach ($matches[0] as $raw) {
            $normalized = $normalizer->normalize((string) $raw);
            if (($normalized['valid'] ?? false) === true && is_string($normalized['normalized'] ?? null)) {
                return $normalized['normalized'];
            }
        }

        return null;
    }

    /**
     * Short yes after soft handoff — e.g. «بنفع !!», «اه», «ماشي».
     */
    public function looksLikeSameNumberAffirmative(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeCampaignOptOut($normalized)) {
            return false;
        }

        // Explicit digits = a different phone, not "same number" affirmative alone.
        if (preg_match('/\d{7,}/', $normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/^('.
            'بنفع|اه+|ا+|ايوه|ايه|نعم|تمام|ماشي|اوك|ok|okay|yes|yep|'.
            'عادي|منيح|اكيد|طبعا|يلا|يلاا?|ليش\s*لا+|'.
            'نفس\s*الرقم|هالرقم|هاذ\s*الرقم|هذا\s*الرقم|عهاذ\s*الرقم|عهاد\s*الرقم|'.
            'تواصل(وا|ي)?\s*معي\s*هون|خليها\s*تحكي\s*معي\s*هون|احك(وا|ي)?\s*معي\s*هون'.
            ')$/u',
            $normalized,
        ) || (bool) preg_match(
            '/(بنفع|ليش\s*لا+|نفس\s*الرقم|هالرقم|عهاذ|عهاد|تواصل(وا|ي)?\s*معي\s*هون|خليها\s*تحكي)/u',
            $normalized,
        );
    }

    /**
     * Customer reply that looks like a real person name (not yes/greeting/price/opt-out).
     */
    public function looksLikeCampaignPersonName(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '' || mb_strlen($normalized) < 2 || mb_strlen($normalized) > 60) {
            return false;
        }

        if ($this->looksLikeSameNumberAffirmative($normalized)
            || $this->looksLikeCampaignOptOut($normalized)
            || $this->looksLikeCampaignConversionIntent($normalized)
            || $this->looksLikeCampaignGreetingOrSmallTalk($normalized)
        ) {
            return false;
        }

        if (preg_match('/\d/', $normalized)) {
            return false;
        }

        if (preg_match('/(سعر|عرض|شهر|نت|انترنت|اشتراك|كيف|ليش|وين|متى|شو\s|ماذا|תשתית|מגדיל)/u', $normalized)) {
            return false;
        }

        // Prefer Arabic/Hebrew letters + spaces (1–5 name tokens).
        if (! preg_match('/^[\p{Arabic}\p{Hebrew}\s\'’\-]+$/u', $normalized)) {
            return false;
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        if (count($parts) < 1 || count($parts) > 5) {
            return false;
        }

        // Lone religious/greeting tokens are never a full name.
        $blockedTokens = ['الله', 'والله', 'اهلين', 'هلا', 'مرحبا', 'سلام', 'تمام', 'اوك', 'ok'];
        if (count($parts) === 1 && in_array($parts[0], $blockedTokens, true)) {
            return false;
        }

        return true;
    }

    private function looksLikeCampaignGreetingOrSmallTalk(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/('.
            'الله\s*يعافيك|الله\s*يعطيك|يعطيك\s*العافيه|يعافيك|'.
            'اهلين|اهلا|أهلا|مرحبا|السلام\s*عليكم|سلام\s*عليكم|'.
            'صباح\s*الخير|مساء\s*الخير|كيفك|كيف حال|شو\s*الاخبار|'.
            'هاي|hello|hi\b|hey\b'.
            ')/u',
            $normalized,
        );
    }

    public function assistantRecentlyAskedSameNumber(ChatbotConversation $conversation): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message');

        foreach ($recent as $message) {
            if ($this->assistantMessageAsksSameNumber((string) $message)) {
                return true;
            }
        }

        return false;
    }

    public function assistantRecentlyAskedForFullName(ChatbotConversation $conversation): bool
    {
        $recent = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message');

        foreach ($recent as $message) {
            $normalized = $this->normalizeCampaignUtterance((string) $message);
            if (
                str_contains($normalized, 'اسمك الكامل')
                || str_contains($normalized, 'الاسم الكامل')
                || (str_contains($normalized, 'اسمك') && str_contains($normalized, 'كامل'))
            ) {
                return true;
            }
        }

        return false;
    }

    private function assistantMessageAsksSameNumber(string $text): bool
    {
        $normalized = $this->normalizeCampaignUtterance($text);
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'عهاذ الرقم')
            || str_contains($normalized, 'عهاد الرقم')
            || str_contains($normalized, 'عنفس الرقم')
            || str_contains($normalized, 'نفس الرقم')
            || (str_contains($normalized, 'تتواصل معك') && str_contains($normalized, 'رقم'))
            || (str_contains($normalized, 'اخليها') && str_contains($normalized, 'رقم'))
            || (str_contains($normalized, 'خليها') && str_contains($normalized, 'رقم'));
    }

    /**
     * Fold Arabic alef variants and strip trailing punctuation / extra spaces.
     */
    private function normalizeCampaignUtterance(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(
            ['أ', 'إ', 'آ', 'ة', 'ى', 'ؤ', 'ئ'],
            ['ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي'],
            $normalized,
        );

        // Drop common punctuation (بنفع !! → بنفع)
        $normalized = preg_replace('/[!！؟?.,،:;…"\'\"\(\)\[\]{}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function assistantAskedForExistingAccountLookup(string $text): bool
    {
        $normalized = mb_strtolower($text);

        // New-lead collection asks for name+city together — not an existing-account lookup.
        if ($this->assistantAskedForNewLeadDetails($text)) {
            return false;
        }

        $asksIdentity = str_contains($normalized, 'هوي') || str_contains($normalized, 'identity');
        $asksRegisteredPhone = (str_contains($normalized, 'تلفون') || str_contains($normalized, 'موبايل') || str_contains($normalized, 'رقم'))
            && (str_contains($normalized, 'مسج') || str_contains($normalized, 'تحقق') || str_contains($normalized, 'فحص'));

        return $asksIdentity || $asksRegisteredPhone || str_contains($normalized, 'للتحقق');
    }

    private function assistantAskedForNewLeadDetails(string $text): bool
    {
        $normalized = mb_strtolower($text);

        $asksName = str_contains($normalized, 'الاسم') || str_contains($normalized, 'اسمك');
        $asksPhone = str_contains($normalized, 'تلفون') || str_contains($normalized, 'موبايل') || str_contains($normalized, 'رقم');
        $asksCity = str_contains($normalized, 'بلدة') || str_contains($normalized, 'منطقة') || str_contains($normalized, 'مدينة');
        $asksRegister = str_contains($normalized, 'أسجّل الطلب')
            || str_contains($normalized, 'اسجل الطلب')
            || str_contains($normalized, 'تسجيل');

        return ($asksName && $asksPhone && $asksCity) || ($asksRegister && ($asksName || $asksPhone));
    }
}
