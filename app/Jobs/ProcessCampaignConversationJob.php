<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\Malan\MalanCampaign;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\Malan\Campaigns\CampaignConversationBurstService;
use App\Services\Malan\Campaigns\CampaignHumanDelay;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\MalanConversationMemoryService;
use App\Services\Malan\MalanLeadService;
use App\Services\Malan\MalanPhoneNormalizer;
use App\Support\HebrewCampaignTerms;
use App\Support\MasculineCustomerAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After the campaign listening window expires: one AI call for the full customer burst,
 * then human typing delays before WhatsApp send (with version checks).
 *
 * Lock is held only during generate/prepare — NOT during typing delays — so a newer
 * customer message can schedule Process vN+1 without being dropped.
 */
class ProcessCampaignConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Seconds to wait for the per-conversation lock before giving up / requeue. */
    private const LOCK_WAIT_SECONDS = 45;

    public const CALLBACK_OFFER_REPLY = 'بتحب نرجعلك بمكالمه بوقت ثاني؟ اذا اه، ايمتا بتكون فاضي؟';

    public const CLOSE_REPLY = 'تمام، يعطيك العافية ونهارك سعيد.';

    public const FIBER_AT_HOME_QUESTION = 'عندكم סיב אופטי بالبيت ولا لسه؟';

    public const JOIN_QUESTION = 'حابب تكون جزء من نجاحنا بالطيبة؟';

    public const COMPETITOR_PERSUASION_REPLY = 'فاهمك. استغل السعر الي عاملينه، أول 3 شهور بس 29 شيقل، وהתקנת סיב אופטי المجانية، بالآخر بعد وقت لو ما عجبتك الخدمة فيك تبدل شركة على نفس התשתית تبعنا. واحنا بنعطي خدمة طول الأسبوع حتى الجمعة والسبت، ولو صار مشكلة بنحلها بنفس اليوم كحد أقصى ثاني يوم.';

    public const GIVE_CHANCE_REPLY = 'بقترح عليك تعطي فرصة تعيش تجربة جديدة مع شركة عربية بتشتغل بمستوى جدا عالي. إنك رح تنبسط بالخدمة، وبالآخر لو ما عجبتك فيك تبدل شركة على نفس התשתית تبعنا.';

    public const NO_FIBER_INSTALL_REPLY = 'تمام، התקנה סיב אופטי وتركيب بشكل عام وبباقي الشركات مثل بيزك بتكون تكلفة بين 300-500 شيقل. احنا عاملين حملة لأول 200 زبون بالطيبة تركيب مجاني تماما.';

    public const NO_FIBER_PRICE_REPLY = 'وأول 3 شهور بس 29 شيقل. بعدين 149 ثابت مدى الحياة.';

    public const SOCIAL_PROOF_REPLY = 'لتقديم حلول و خدمات انترنت متطورة / سرعات عالية و اسعار مناسبة. دعم تقني على مدار الساعة طيلة ايام الاسبوع. طبعا احنا شركه حاصله على رخص من משרד התקשורת وبنشتغل بمستوى حرفي جدا جدا. الزباين مبسوطه واصحاب المصالح مكيفين وهاي احد الفيديوهات من اراء الزباين بخدمتنا https://www.instagram.com/reel/DOs3eKXDNa7/?igsi=YWJkd3M3aWllZDRm';

    public const SAME_NUMBER_ASK_REPLY = 'اذا حابب تنضم النا، بقدر اخلي الصبيه تتواصل معك كمان شوي تشرحلك اكثر. بدك اخليها تتواصل معك عهاذ الرقم؟';

    public const ASK_NAME_REPLY = 'تمام، اعطيني اسمك الكامل بس.';

    public function __construct(
        public int $campaignId,
        public int $conversationId,
        public int $expectedVersion,
        public string $chatId,
    ) {}

    public function handle(
        AiChatbotService $chatbotService,
        ChatbotGreenApiService $greenApi,
        CampaignConversationBurstService $burstService,
        MalanConversationContextService $contextService,
    ): void {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        $lock = Cache::lock('campaign-burst-process-'.$this->conversationId, 120);
        $acquired = $this->waitForLock($lock, $burstService);
        if (! $acquired) {
            $this->requeueIfStillLatest($burstService);

            return;
        }

        $scheduled = [];
        try {
            $scheduled = $this->generateAndPrepare(
                $chatbotService,
                $burstService,
                $contextService,
            );
        } finally {
            optional($lock)->release();
        }

        if ($scheduled === []) {
            return;
        }

        // Typing delays happen OUTSIDE the lock so a newer inbound can process.
        $this->deliverWithTypingDelays($greenApi, $scheduled);
    }

    private function waitForLock(
        mixed $lock,
        CampaignConversationBurstService $burstService,
    ): bool {
        $deadline = microtime(true) + $this->lockWaitSeconds();

        while (microtime(true) < $deadline) {
            $conversation = ChatbotConversation::query()->find($this->conversationId);
            if ($conversation === null) {
                return false;
            }
            if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
                return false;
            }

            if ($lock->get()) {
                return true;
            }

            usleep(app()->runningUnitTests() ? 50_000 : 300_000);
        }

        return false;
    }

    private function lockWaitSeconds(): int
    {
        return app()->runningUnitTests() ? 1 : self::LOCK_WAIT_SECONDS;
    }

    private function requeueIfStillLatest(CampaignConversationBurstService $burstService): void
    {
        $conversation = ChatbotConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return;
        }

        Log::info('Campaign burst process requeued after lock wait', [
            'conversation_id' => $this->conversationId,
            'version' => $this->expectedVersion,
        ]);

        self::dispatch(
            $this->campaignId,
            $this->conversationId,
            $this->expectedVersion,
            $this->chatId,
        )->delay(now()->addSeconds(2));
    }

    /**
     * @return list<array{message_id:int,delay_seconds:float}>
     */
    private function generateAndPrepare(
        AiChatbotService $chatbotService,
        CampaignConversationBurstService $burstService,
        MalanConversationContextService $contextService,
    ): array {
        $campaign = MalanCampaign::query()->find($this->campaignId);
        $conversation = ChatbotConversation::query()->find($this->conversationId);

        if ($campaign === null || $conversation === null) {
            return [];
        }

        if (! $campaign->isBotActive() || ! $conversation->allowsAutomaticReply()) {
            return [];
        }

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            return [];
        }

        $instance = $campaign->instance;
        $user = $instance?->user;
        if ($instance === null || $user === null) {
            return [];
        }

        $burst = $burstService->collectUnprocessedBurst($conversation);
        if ($burst === []) {
            $burstService->closeBurst($conversation, $this->expectedVersion);

            return [];
        }

        $burstService->setResponseState($conversation, CampaignConversationBurstService::STATE_GENERATING);
        $contextService->beginCampaignSales($conversation, $instance);

        $memory = app(MalanConversationMemoryService::class);
        foreach ($burst as $userMessage) {
            $memory->observeUserMessage($conversation, $instance, (string) $userMessage->message);
        }

        $triggerUserMessageId = (int) $burst[array_key_last($burst)]->id;
        $lastUserText = (string) $burst[array_key_last($burst)]->message;

        $fixedReply = $this->resolveDeterministicLeadHandoffReply(
            $conversation,
            $instance,
            $memory,
            $contextService,
            $lastUserText,
        );

        if ($fixedReply !== null) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'sender_type' => 'ai',
                'message_type' => 'text',
                'reply_source' => ChatbotMessage::REPLY_SOURCE_SYSTEM,
                'message' => $fixedReply,
                'metadata' => [
                    'campaign_deterministic_handoff' => true,
                ],
            ]);
            $conversation->recordAssistantActivity();
            $memory->observeAssistantMessage($conversation, $instance, $fixedReply);

            $burstService->markBurstProcessed($burst, $this->expectedVersion);
            $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);

            return $this->prepareOutboundBubbles(
                $campaign,
                $conversation,
                $assistantMessage,
                $triggerUserMessageId,
                $this->expectedVersion,
            );
        }

        $ephemeral = $burstService->buildBurstEphemeralPrompt($burst, $instance);

        try {
            $result = $chatbotService->generateAssistantReplyForConversation(
                $conversation->fresh() ?? $conversation,
                $instance,
                ChatbotConversation::CHANNEL_WHATSAPP,
                false,
                $ephemeral,
            );
        } catch (Throwable $e) {
            Log::error('Campaign burst AI failed', [
                'campaign_id' => $this->campaignId,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
            $burstService->setResponseState($conversation, CampaignConversationBurstService::STATE_WAITING_FOR_CUSTOMER);

            return [];
        }

        $conversation->refresh();
        if ($burstService->currentVersion($conversation) !== $this->expectedVersion) {
            $assistant = $result['assistant_message'] ?? null;
            if ($assistant instanceof ChatbotMessage) {
                $meta = is_array($assistant->metadata) ? $assistant->metadata : [];
                $assistant->forceFill([
                    'delivery_status' => 'failed',
                    'metadata' => array_merge($meta, [
                        'campaign_id' => $this->campaignId,
                        'campaign_delivery' => CampaignConversationBurstService::STATE_CANCELLED,
                        'campaign_response_state' => CampaignConversationBurstService::STATE_STALE,
                        'campaign_cancel_reason' => 'version_changed_after_generate',
                        'campaign_generated_for_version' => $this->expectedVersion,
                    ]),
                ])->save();
            }

            return [];
        }

        $assistantMessage = $result['assistant_message'] ?? null;
        if (! $assistantMessage instanceof ChatbotMessage) {
            return [];
        }

        $burstService->markBurstProcessed($burst, $this->expectedVersion);
        $burstService->closeBurst($conversation->fresh() ?? $conversation, $this->expectedVersion);

        return $this->prepareOutboundBubbles(
            $campaign,
            $conversation,
            $assistantMessage,
            $triggerUserMessageId,
            $this->expectedVersion,
        );
    }

    /**
     * Hard stop the same-number / name loop without relying on the model.
     */
    private function resolveDeterministicLeadHandoffReply(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        MalanConversationMemoryService $memory,
        MalanConversationContextService $contextService,
        string $lastUserText,
    ): ?string {
        $context = $contextService->getActive($conversation);

        $localize = static fn (string $text): string => $instance->companyProfile()->localize($text);

        if ($contextService->isCampaignOptedOut($context)) {
            return $localize(self::CLOSE_REPLY);
        }

        if ($memory->lastAssistantAskedFiberAtHome($conversation)) {
            if ($memory->looksLikeNoFiberAtHome($lastUserText) || $memory->looksLikeBareNo($lastUserText)) {
                return $localize(self::NO_FIBER_INSTALL_REPLY)."\n\n".$localize(self::NO_FIBER_PRICE_REPLY)."\n\n".$localize(self::JOIN_QUESTION);
            }

            if ($memory->looksLikeHasFiberAtHome($lastUserText)
                || $memory->looksLikeBareYes($lastUserText)
                || $memory->looksLikeJoinAffirmative($lastUserText)
            ) {
                return $localize(self::JOIN_QUESTION);
            }
        }

        if ($memory->looksLikeJoinAffirmative($lastUserText)
            && $memory->assistantRecentlyAskedClosingQuestion($conversation)
        ) {
            if ($contextService->campaignPhoneConfirmed($context)) {
                return self::ASK_NAME_REPLY;
            }

            return $localize(self::SAME_NUMBER_ASK_REPLY);
        }

        if ($memory->looksLikeAsksAboutCustomerReviews($lastUserText)) {
            $turn = $memory->campaignTurnMemory($conversation);
            $hasSalesContext = ($turn['fiber_asked'] ?? false)
                || ($turn['closing_question_asked'] ?? false)
                || ($turn['company_pitch_sent'] ?? false)
                || ($turn['persuasion_sent'] ?? false);
            if ($hasSalesContext && ! ($turn['social_proof_sent'] ?? false)) {
                return $localize(self::SOCIAL_PROOF_REPLY);
            }
        }

        if ($memory->looksLikeWantsToKeepTalking($lastUserText)
            && ! $memory->looksLikeCompetitorLoyalty($lastUserText)
            && ! $memory->looksLikeSoftStayObjection($lastUserText)
        ) {
            $turn = $memory->campaignTurnMemory($conversation);
            $introStarted = ($turn['fiber_asked'] ?? false)
                || ($turn['closing_question_asked'] ?? false)
                || ($turn['company_pitch_sent'] ?? false);
            if ($introStarted && ! ($turn['social_proof_sent'] ?? false)) {
                return $localize(self::SOCIAL_PROOF_REPLY);
            }

            return null;
        }

        if ($memory->looksLikeHardCampaignOptOut($lastUserText)) {
            return $localize(self::CLOSE_REPLY);
        }

        $softNo = ! $memory->looksLikeJoinAffirmative($lastUserText)
            && ($memory->looksLikeCompetitorLoyalty($lastUserText)
            || $memory->looksLikeSoftStayObjection($lastUserText)
            || $memory->looksLikeSoftRefusal($lastUserText)
            || ($memory->assistantRecentlyAskedClosingQuestion($conversation) && $memory->looksLikeBareNo($lastUserText)));

        if ($softNo) {
            if ($memory->assistantRecentlySentGiveChance($conversation)) {
                return $localize(self::CLOSE_REPLY);
            }
            if ($memory->assistantRecentlySentCompetitorPersuasion($conversation)) {
                return $localize(self::GIVE_CHANCE_REPLY);
            }

            return $localize(self::COMPETITOR_PERSUASION_REPLY);
        }

        $extractedPhone = $memory->extractWrittenCampaignPhone($lastUserText);
        $awaitingContactDetails = $memory->assistantRecentlyAskedSameNumber($conversation)
            || $memory->assistantRecentlyAskedForFullName($conversation)
            || $contextService->isCampaignLeadCollectionMode($context);

        // Written 05… after the number (or name) question is the contact phone, never the name.
        if ($extractedPhone !== null && $awaitingContactDetails) {
            $contextService->rememberCampaignLeadPhone($conversation, $instance, $extractedPhone);

            return self::ASK_NAME_REPLY;
        }

        if (! $contextService->campaignPhoneConfirmed($context)) {
            return null;
        }

        $askedForName = $memory->assistantRecentlyAskedForFullName($conversation);

        // Never invent a lead name from greetings / sales chatter — only after we asked for الاسم الكامل.
        if ($askedForName && $memory->looksLikeCampaignPersonName($lastUserText)) {
            $fullName = trim(preg_replace('/\s+/u', ' ', $lastUserText) ?? $lastUserText);
            $phone = $this->resolveCampaignWhatsAppPhone($conversation, $contextService->getActive($conversation));
            if ($phone === null || $phone === '') {
                Log::warning('Campaign handoff: WhatsApp phone unavailable for lead', [
                    'conversation_id' => $conversation->id,
                ]);

                return self::ASK_NAME_REPLY;
            }

            $leadResult = app(MalanLeadService::class)->createFromConversation($instance, $conversation, [
                'full_name' => $fullName,
                'phone' => $phone,
                'city_name' => $instance->campaignDefaultCity(),
                'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                'metadata' => [
                    'via' => 'campaign_deterministic_handoff',
                    'confirmed_by_customer' => true,
                ],
            ]);

            if (($leadResult['success'] ?? false) !== true) {
                Log::warning('Campaign handoff lead create failed', [
                    'conversation_id' => $conversation->id,
                    'result' => $leadResult,
                ]);

                return self::ASK_NAME_REPLY;
            }

            $parts = preg_split('/\s+/u', $fullName) ?: [];
            $firstName = trim((string) ($parts[0] ?? $fullName));

            return 'تمام '.$firstName.'، رح تتواصل معك الصبيه كمان شوي 👍';
        }

        // Affirmative after same-number ask → ask for name.
        // If we already asked for a name and got junk/greeting → ask again (opt-out handled above).
        if ($memory->looksLikeSameNumberAffirmative($lastUserText) || $askedForName) {
            return self::ASK_NAME_REPLY;
        }

        // Stale contact_on_current_number without a fresh name ask (e.g. after re-trigger) → let AI sell.
        return null;
    }

    private function resolveCampaignWhatsAppPhone(
        ChatbotConversation $conversation,
        ?ChatbotConversationContext $context = null,
    ): ?string {
        $candidates = [];
        if ($context !== null) {
            $extra = is_array($context->context) ? $context->context : [];
            $leadPhone = trim((string) ($extra['campaign_lead_phone'] ?? ''));
            if ($leadPhone !== '') {
                $candidates[] = $leadPhone;
            }
        }
        if (is_string($conversation->contact_phone) && trim($conversation->contact_phone) !== '') {
            $candidates[] = trim($conversation->contact_phone);
        }
        if (is_string($conversation->external_chat_id) && trim($conversation->external_chat_id) !== '') {
            $fromChat = preg_replace('/@.*$/', '', trim($conversation->external_chat_id));
            // campaign:{id}:{phone}@c.us → take last segment before @
            if (is_string($fromChat) && str_contains($fromChat, ':')) {
                $parts = explode(':', $fromChat);
                $fromChat = (string) end($parts);
            }
            if (is_string($fromChat) && $fromChat !== '') {
                $candidates[] = $fromChat;
            }
        }

        $normalizer = new MalanPhoneNormalizer;
        foreach ($candidates as $candidate) {
            $normalized = $normalizer->normalize($candidate);
            if (($normalized['valid'] ?? false) === true && is_string($normalized['normalized'] ?? null)) {
                return $normalized['normalized'];
            }
        }

        return null;
    }

    /**
     * @return list<array{message_id:int,delay_seconds:float,closing_question:bool}>
     */
    private function prepareOutboundBubbles(
        MalanCampaign $campaign,
        ChatbotConversation $conversation,
        ChatbotMessage $firstMessage,
        int $triggerUserMessageId,
        int $generatedForVersion,
    ): array {
        $fullReply = MasculineCustomerAddress::sanitize(
            HebrewCampaignTerms::sanitize((string) $firstMessage->message),
        );
        $bubbles = CampaignHumanDelay::splitBubbles($fullReply);
        if ($bubbles === []) {
            return [];
        }

        $scheduled = [];
        $offsetSeconds = 0;

        foreach ($bubbles as $index => $bubble) {
            $bubble = MasculineCustomerAddress::sanitize(HebrewCampaignTerms::sanitize($bubble));
            if ($bubble === '') {
                continue;
            }

            if ($index > 0) {
                $offsetSeconds += CampaignHumanDelay::secondsBetweenBubbles();
            }

            $typingSeconds = CampaignHumanDelay::secondsForText($bubble);
            $delaySeconds = $offsetSeconds + $typingSeconds;
            $offsetSeconds = $delaySeconds;

            $baseMeta = [
                'campaign_id' => $campaign->id,
                'campaign_delivery' => 'queued',
                'campaign_response_state' => CampaignConversationBurstService::STATE_PENDING_SEND,
                'campaign_send_after' => now()->addSeconds($delaySeconds)->toIso8601String(),
                'trigger_user_message_id' => $triggerUserMessageId,
                'campaign_generated_for_version' => $generatedForVersion,
                'greenapi_chat_id' => $this->chatId,
            ];

            if ($index === 0) {
                $meta = is_array($firstMessage->metadata) ? $firstMessage->metadata : [];
                $firstMessage->forceFill([
                    'message' => $bubble,
                    'delivery_status' => 'pending',
                    'metadata' => array_merge($meta, $baseMeta),
                ])->save();
                $message = $firstMessage;
            } else {
                $message = ChatbotMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'sender_type' => 'ai',
                    'reply_source' => ChatbotMessage::REPLY_SOURCE_AI,
                    'message_type' => 'text',
                    'message' => $bubble,
                    'delivery_status' => 'pending',
                    'metadata' => array_merge($baseMeta, [
                        'campaign_bubble_index' => $index,
                    ]),
                ]);
            }

            SendDelayedCampaignWhatsAppJob::dispatch(
                (int) $campaign->id,
                (int) $message->id,
                $this->chatId,
                $triggerUserMessageId,
                $generatedForVersion,
            )->delay(now()->addSeconds($delaySeconds));

            $asksClosingQuestion = SendCampaignFollowUpNudgeJob::textAsksClosingQuestion($bubble);
            if ($asksClosingQuestion) {
                SendCampaignFollowUpNudgeJob::dispatch(
                    (int) $campaign->id,
                    (int) $conversation->id,
                    $this->chatId,
                    $triggerUserMessageId,
                    (int) $message->id,
                    $generatedForVersion,
                )->delay(now()->addSeconds($delaySeconds + SendCampaignFollowUpNudgeJob::DELAY_SECONDS));
            }

            $scheduled[] = [
                'message_id' => (int) $message->id,
                'delay_seconds' => $delaySeconds,
                'closing_question' => $asksClosingQuestion,
            ];
        }

        return $scheduled;
    }

    /**
     * @param  list<array{message_id:int,delay_seconds:float,closing_question?:bool}>  $scheduled
     */
    private function deliverWithTypingDelays(ChatbotGreenApiService $greenApi, array $scheduled): void
    {
        if ($scheduled === []) {
            return;
        }

        $campaign = MalanCampaign::query()->find($this->campaignId);
        $sendUrl = trim((string) ($campaign?->greenapi_url ?? ''));
        $maxDelay = 0.0;
        foreach ($scheduled as $row) {
            $maxDelay = max($maxDelay, (float) ($row['delay_seconds'] ?? 0));
        }
        @set_time_limit(max(60, (int) ceil($maxDelay) + 45));

        $started = microtime(true);
        foreach ($scheduled as $row) {
            $messageId = (int) ($row['message_id'] ?? 0);
            $delaySeconds = (float) ($row['delay_seconds'] ?? 0);
            if ($messageId <= 0) {
                continue;
            }

            $remaining = $delaySeconds - (microtime(true) - $started);
            if ($remaining > 0.2 && $sendUrl !== '') {
                try {
                    // Keep "typing…" mostly on during the wait (renew long pulses + short pauses).
                    $greenApi->sustainTypingPresence($sendUrl, $this->chatId, $remaining);
                } catch (Throwable) {
                    $still = $delaySeconds - (microtime(true) - $started);
                    if ($still > 0) {
                        usleep((int) round($still * 1_000_000));
                    }
                }
            } elseif ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }

            try {
                $metaTrigger = ChatbotMessage::query()->find($messageId);
                $meta = is_array($metaTrigger?->metadata) ? $metaTrigger->metadata : [];
                $triggerId = (int) ($meta['trigger_user_message_id'] ?? 0);
                $job = new SendDelayedCampaignWhatsAppJob(
                    $this->campaignId,
                    $messageId,
                    $this->chatId,
                    $triggerId,
                    $this->expectedVersion,
                    skipPreType: true,
                );
                $job->handle($greenApi);
            } catch (Throwable $e) {
                Log::warning('Campaign burst typing-delay send failed', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $closing = null;
        foreach ($scheduled as $row) {
            if (($row['closing_question'] ?? false) === true) {
                $closing = $row;
            }
        }

        if ($closing !== null) {
            $this->waitThenNudge($greenApi, (int) $closing['message_id']);
        }
    }

    /**
     * Shared hosting has no queue worker, so the 3-minute wait runs inline here
     * and bails out as soon as the customer answers.
     */
    private function waitThenNudge(
        ChatbotGreenApiService $greenApi,
        int $askedMessageId,
    ): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $asked = ChatbotMessage::query()->find($askedMessageId);
        $meta = is_array($asked?->metadata) ? $asked->metadata : [];
        $triggerUserMessageId = (int) ($meta['trigger_user_message_id'] ?? 0);

        @set_time_limit(SendCampaignFollowUpNudgeJob::DELAY_SECONDS + 90);

        $deadline = microtime(true) + SendCampaignFollowUpNudgeJob::DELAY_SECONDS;
        while (microtime(true) < $deadline) {
            sleep(5);

            $latestUserId = (int) ChatbotMessage::query()
                ->where('conversation_id', $this->conversationId)
                ->where('role', 'user')
                ->max('id');

            if ($latestUserId > $triggerUserMessageId) {
                return;
            }
        }

        try {
            (new SendCampaignFollowUpNudgeJob(
                $this->campaignId,
                $this->conversationId,
                $this->chatId,
                $triggerUserMessageId,
                $askedMessageId,
                $this->expectedVersion,
            ))->handle($greenApi);
        } catch (Throwable $e) {
            Log::warning('Campaign follow-up nudge failed', [
                'conversation_id' => $this->conversationId,
                'asked_message_id' => $askedMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
