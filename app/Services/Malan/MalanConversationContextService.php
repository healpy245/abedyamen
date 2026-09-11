<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationContext;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
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
        $extra['mode'] = 'existing_support';
        if (is_array($result->radius)) {
            $extra['radius'] = $result->radius;
        }

        // Successful account lookup always exits new-signup / campaign sales modes.
        $resolvedPending = $pendingFlow;
        if (in_array((string) $context->pending_flow, [
            'new_signup_intake',
            'new_lead_open',
            'campaign_sales_conversation',
            'campaign_lead_collection',
        ], true)) {
            $resolvedPending = $pendingFlow ?: 'internet_outage';
        }

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => $result->customer['id'] ?? null,
            'verified_customer_name' => $result->customer['name'] ?? null,
            'verified_phone_masked' => $result->customer['phone_masked'] ?? null,
            'verified_identity_masked' => $result->customer['identity_masked'] ?? null,
            'customer_status' => $result->customer['status'] ?? null,
            'debt_amount' => $result->financial['debt_amount'] ?? null,
            'pending_flow' => $resolvedPending,
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
     * Hard reset for a fresh campaign blast — wipe history + clear lead-collection flags.
     */
    public function resetCampaignConversationForResend(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->delete();

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        unset(
            $meta['campaign_burst_open'],
            $meta['campaign_burst_started_at'],
            $meta['campaign_pending_process_version'],
            $meta['campaign_response_state'],
        );
        // Keep identity keys; bump version so any in-flight delayed sends become stale.
        $meta['campaign_conversation_version'] = ((int) ($meta['campaign_conversation_version'] ?? 0)) + 1;
        $meta['campaign_lead_bot'] = true;
        $conversation->forceFill([
            'metadata' => $meta,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'title' => $conversation->title ?: 'Campaign',
        ])->save();

        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];
        unset(
            $extra['contact_on_current_number'],
            $extra['campaign_lead_phone'],
            $extra['campaign_phone_confirmed'],
            $extra['campaign_has_fiber'],
            $extra['campaign_opted_out'],
            $extra['campaign_callback_offered'],
            $extra['campaign_callback_offered_at'],
            $extra['radius'],
        );
        $extra['mode'] = 'campaign_sales';

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => null,
            'verified_customer_name' => null,
            'verified_phone_masked' => null,
            'verified_identity_masked' => null,
            'customer_status' => null,
            'debt_amount' => null,
            'payment_method' => null,
            'pending_flow' => 'campaign_sales_conversation',
            'context' => $extra,
        ]);
        $context->save();

        return $context->fresh() ?? $context;
    }

    /**
     * Campaign sales conversation — persuade first; not central new-signup intake.
     */
    public function beginCampaignSales(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];

        // Never downgrade an active lead-collection phase back to sales chatter.
        if ($this->isCampaignLeadCollectionMode($context)) {
            return $context;
        }

        $extra['mode'] = 'campaign_sales';
        unset($extra['radius']);

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => null,
            'verified_customer_name' => null,
            'verified_phone_masked' => null,
            'verified_identity_masked' => null,
            'customer_status' => null,
            'debt_amount' => null,
            'payment_method' => null,
            'pending_flow' => 'campaign_sales_conversation',
            'context' => $extra,
        ]);
        $context->save();

        return $context->fresh() ?? $context;
    }

    /**
     * Customer wants to hear more / chat first — leave lead-collection pressure
     * unless the WhatsApp number is already locked.
     */
    public function resumeCampaignSalesForQuestions(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        if ($this->campaignPhoneConfirmed($context)) {
            return $context;
        }

        $extra = is_array($context->context) ? $context->context : [];
        $extra['mode'] = 'campaign_sales';
        $extra['campaign_wants_chat'] = true;
        unset($extra['radius']);

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'pending_flow' => 'campaign_sales_conversation',
            'context' => $extra,
        ]);
        $context->save();

        return $context->fresh() ?? $context;
    }

    /**
     * Campaign conversion — customer showed buying intent; collect lead one field at a time.
     */
    public function beginCampaignLeadCollection(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];
        $extra['mode'] = 'campaign_lead_collection';
        unset($extra['radius']);
        // Preserve contact_on_current_number / opted_out if already set this chat.

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => null,
            'verified_customer_name' => null,
            'verified_phone_masked' => null,
            'verified_identity_masked' => null,
            'customer_status' => null,
            'debt_amount' => null,
            'payment_method' => null,
            'pending_flow' => 'campaign_lead_collection',
            'context' => $extra,
        ]);
        $context->save();

        return $context->fresh() ?? $context;
    }

    /**
     * Persist a campaign conversation flag inside context JSON.
     *
     * @param  array<string, mixed>  $flags
     */
    public function mergeCampaignFlags(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        array $flags,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];
        foreach ($flags as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $extra[$key] = $value;
        }
        $context->forceFill(['context' => $extra, 'chatbot_instance_id' => $instance->id])->save();

        return $context->fresh() ?? $context;
    }

    public function markContactOnCurrentNumber(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->mergeCampaignFlags($conversation, $instance, [
            'contact_on_current_number' => true,
            'mode' => 'campaign_lead_collection',
        ]);
        if ((string) $context->pending_flow !== 'campaign_lead_collection') {
            $context->forceFill(['pending_flow' => 'campaign_lead_collection'])->save();
        }

        return $context->fresh() ?? $context;
    }

    public function rememberCampaignLeadPhone(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $phone,
    ): ChatbotConversationContext {
        $context = $this->mergeCampaignFlags($conversation, $instance, [
            'campaign_lead_phone' => $phone,
            'campaign_phone_confirmed' => true,
            'contact_on_current_number' => false,
            'mode' => 'campaign_lead_collection',
        ]);
        if ((string) $context->pending_flow !== 'campaign_lead_collection') {
            $context->forceFill(['pending_flow' => 'campaign_lead_collection'])->save();
        }

        return $context->fresh() ?? $context;
    }

    public function campaignLeadPhone(?ChatbotConversationContext $context): ?string
    {
        if ($context === null) {
            return null;
        }
        $extra = is_array($context->context) ? $context->context : [];
        $phone = trim((string) ($extra['campaign_lead_phone'] ?? ''));

        return $phone !== '' ? $phone : null;
    }

    public function campaignPhoneConfirmed(?ChatbotConversationContext $context): bool
    {
        return $this->campaignContactOnCurrentNumber($context)
            || $this->campaignLeadPhone($context) !== null;
    }

    public function rememberCampaignFiber(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        bool $hasFiber,
    ): ChatbotConversationContext {
        return $this->mergeCampaignFlags($conversation, $instance, [
            'campaign_has_fiber' => $hasFiber,
        ]);
    }

    public function campaignHasFiber(?ChatbotConversationContext $context): ?bool
    {
        if ($context === null) {
            return null;
        }
        $extra = is_array($context->context) ? $context->context : [];
        if (! array_key_exists('campaign_has_fiber', $extra)) {
            return null;
        }

        return (bool) $extra['campaign_has_fiber'];
    }

    public function markCampaignOptedOut(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->mergeCampaignFlags($conversation, $instance, [
            'campaign_opted_out' => true,
            'mode' => 'campaign_opted_out',
        ]);
        $context->forceFill(['pending_flow' => 'campaign_opted_out'])->save();

        return $context->fresh() ?? $context;
    }

    /**
     * First refusal: offer a callback instead of closing the chat.
     */
    public function markCampaignCallbackOffered(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        return $this->mergeCampaignFlags($conversation, $instance, [
            'campaign_callback_offered' => true,
            'campaign_callback_offered_at' => now()->toIso8601String(),
        ]);
    }

    public function campaignCallbackOffered(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }
        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['campaign_callback_offered'] ?? false) === true;
    }

    public function campaignContactOnCurrentNumber(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }
        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['contact_on_current_number'] ?? false) === true;
    }

    public function isCampaignOptedOut(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }
        if ((string) $context->pending_flow === 'campaign_opted_out') {
            return true;
        }
        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['campaign_opted_out'] ?? false) === true
            || ($extra['mode'] ?? null) === 'campaign_opted_out';
    }

    public function isCampaignSalesMode(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }

        if ((string) $context->pending_flow === 'campaign_sales_conversation') {
            return true;
        }

        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['mode'] ?? null) === 'campaign_sales';
    }

    public function isCampaignLeadCollectionMode(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }

        if ((string) $context->pending_flow === 'campaign_lead_collection') {
            return true;
        }

        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['mode'] ?? null) === 'campaign_lead_collection';
    }

    public function isCampaignMode(?ChatbotConversationContext $context): bool
    {
        return $this->isCampaignSalesMode($context) || $this->isCampaignLeadCollectionMode($context);
    }

    /**
     * Exclusive new-signup mode: clears any prior verified customer so lookup cannot bleed in.
     */
    public function beginNewSignup(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];
        $extra['mode'] = 'new_signup';
        unset($extra['radius']);

        $context->fill([
            'chatbot_instance_id' => $instance->id,
            'verified_customer_id' => null,
            'verified_customer_name' => null,
            'verified_phone_masked' => null,
            'verified_identity_masked' => null,
            'customer_status' => null,
            'debt_amount' => null,
            'payment_method' => null,
            'pending_flow' => 'new_signup_intake',
            'context' => $extra,
        ]);
        $context->save();

        return $context->fresh();
    }

    /**
     * Switch to existing-customer / outage support: leave signup mode.
     */
    public function beginExistingSupport(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        ?string $pendingFlow = 'internet_outage',
    ): ChatbotConversationContext {
        $context = $this->getOrCreate($conversation, $instance);
        $extra = is_array($context->context) ? $context->context : [];
        $extra['mode'] = 'existing_support';
        $context->context = $extra;

        if (in_array((string) $context->pending_flow, [
            'new_signup_intake',
            'new_lead_open',
            'campaign_sales_conversation',
            'campaign_lead_collection',
        ], true)) {
            $context->pending_flow = $pendingFlow;
        } elseif ($context->pending_flow === null || $context->pending_flow === '') {
            $context->pending_flow = $pendingFlow;
        }

        $context->save();

        return $context->fresh();
    }

    public function isNewSignupMode(?ChatbotConversationContext $context): bool
    {
        if ($context === null) {
            return false;
        }

        // Campaign modes are NOT central new-signup intake.
        if ($this->isCampaignMode($context)) {
            return false;
        }

        if (in_array((string) $context->pending_flow, ['new_signup_intake', 'new_lead_open'], true)) {
            return true;
        }

        $extra = is_array($context->context) ? $context->context : [];

        return ($extra['mode'] ?? null) === 'new_signup';
    }

    /**
     * Safe summary injected into the model context (never trust AI memory alone).
     *
     * @return array<string, mixed>|null
     */
    public function toPromptSummary(?ChatbotConversationContext $context, ?\App\Models\AiChatbot\ChatbotInstance $instance = null): ?array
    {
        if ($context === null) {
            return null;
        }

        if ($this->isCampaignSalesMode($context)) {
            $extra = is_array($context->context) ? $context->context : [];
            $memory = $this->campaignMemoryForContext($context);

            return $this->withLocalizedRules([
                'mode' => 'campaign_sales',
                'pending_flow' => $context->pending_flow,
                'contact_on_current_number' => ($extra['contact_on_current_number'] ?? false) === true,
                'campaign_opted_out' => ($extra['campaign_opted_out'] ?? false) === true,
                'campaign_wants_chat' => ($extra['campaign_wants_chat'] ?? false) === true,
                'thread' => $this->campaignThreadForContext($context),
                'already_covered' => [
                    'intro_asked' => $memory['intro_asked'],
                    'company_pitch_sent' => $memory['company_pitch_sent'],
                    'fiber_asked' => $memory['fiber_asked'] ?? false,
                    'closing_question_asked' => $memory['closing_question_asked'],
                    'callback_offered' => $memory['callback_offered'] || (($extra['campaign_callback_offered'] ?? false) === true),
                    'persuasion_sent' => $memory['persuasion_sent'] ?? false,
                    'give_chance_sent' => $memory['give_chance_sent'] ?? false,
                    'social_proof_sent' => $memory['social_proof_sent'] ?? false,
                ],
                'do_not_repeat' => $memory['do_not_repeat'],
                'rules' => [
                    'MODE=campaign_sales. Spoken Palestinian/Arab-Israeli WhatsApp Arabic ONLY — never MSA/فصحى. Stay dialect for the WHOLE conversation; never drift formal mid-chat.',
                    'Address the customer as MALE always (تحب/تكمل/حابب/بدك). NEVER تحبي/تكملي/حابة. الصبيه stays feminine.',
                    'NEVER use ! exclamation marks in replies.',
                    'Hebrew: always סיב אופטי, מגדיל טווח, and בזק exact Hebrew only (never mix Arabic letters inside those words). Dialect بيزك is OK when the whole word is Arabic. תשתית וספק (NO dash before ספק) when it comes up.',
                    'Read already_covered + do_not_repeat + thread. This is the FULL WhatsApp thread for this phone from first message to last. NEVER restart "اكيد بتعرفنا صح؟" if intro_asked=true. NEVER resend the 5-towns pitch if company_pitch_sent=true. NEVER re-ask fiber-at-home if fiber_asked=true. NEVER re-ask "حابب تكون جزء من نجاحنا" after closing_question_asked=true. NEVER send Instagram/solutions if social_proof_sent=true. Memory resets ONLY if staff hits campaign trigger for this number.',
                    'ليش لا / يلا / اكيد / تمام / ماشي / فش مشكلة after «حابب تكون جزء من نجاحنا» = YES they want to join. Continue positively with the same-number ask. NEVER treat ليش لا as لا/refusal. NEVER send persuasion after a yes.',
                    'Who is Sally / first contact ONLY if intro_asked=false → ONE bubble: "انا سالي من شركة ملان، اكيد بتعرفنا صح؟" then WAIT. If they later ask "انت ذكاء اصطناعي؟": اه أنا ذكاء اصطناعي بتابع مع الزباين حتى بأوقات متأخرة. قسم المبيعات من 9 الصبح لـ6 المسا.',
                    'If they KNOW Malan → short "مثل ما بتعرف، ملان انترنت الشركة العربية الاولى في المنطقة لتقديم خدمات الانترنت السريع סיב אופטי." then AUTO short "بعد نجاح رهيب… الطيبة البلد السادس" then "عندكم סיב אופטי بالبيت ولا لسه؟" and WAIT. If they do NOT know AND company_pitch_sent=false → exactly 3 bubbles: (1) company intro (2) short 5-towns line ONLY (3) fiber-at-home question. NEVER Instagram/solutions/"لتقديم حلول" in the intro. NEVER ask "حابب تكون جزء من نجاحنا" until after the fiber answer. If fiber=yes → join question with ؟. If fiber=no → install 300-500 elsewhere vs free for first 200 in Taybee, then 29/149, then join. NEVER mention switching companies or واثقين until they refuse.',
                    'Sales hours: قسم المبيعات from 9:00 to 18:00. Sally is AI and can chat late. Technical support 24/7 / Friday-Saturday is NOT sales hours. NEVER say sales is 24/7. NEVER append the closing question after a hours answer.',
                    'Competitor stay (بيزك/בזק/Bezeq/هوت/HOT/הוט/another ISP/مرتاح/بلزمش): NOT a callback. 1) Persuade: استغل السعر، أول 3 شهور بس 29 شيقل، وהתקנת סיב אופטי المجانية، بالآخر لو ما عجبتك فيك تبدل شركة على نفس התשתית. Grammar تنبسط never ينبسط. Plus weekend/same-day fix. 2) Give-a-chance. 3) Close "تمام، يعطيك العافية ونهارك سعيد." NEVER "بتحب نرجعلك بمكالمه بوقت ثاني؟".',
                    'תשתית וספק is ASK-ONLY except on competitor objection. Only when the customer asks, reply short "احنا بنقدم תשתית וספק خاص فينا كامل."',
                    'Service advantages (عربية من الألف للياء / تركيب ودعم فني / الجمعة والسبت / بنفس اليوم أو أقصى ثاني يوم) are ASK-ONLY in the intro — but MUST be used on competitor/comfortable-elsewhere objection.',
                    'Hard opt-out (حلي عني / باي / لا تبعثولي) → "تمام، يعطيك العافية ونهارك سعيد." Soft no (مش مهتم / مش حابب) uses the same 3-step persuasion ladder, never the old callback question.',
                    'Mid-chat ONLY: if they ask about other customers (شو راي الزباين / اللكوحوت / مبسوطين / آراء) or want to hear more and social_proof_sent=false, send the solutions+Instagram testimonials bubble once (full Instagram URL). NEVER in the opening sequence.',
                    'Next-step questions (وانا شو اعملكم / شو اعمل / وبعدين / شو المطلوب / كيف بنضم / كيف اشترك) are NOT "مهتم" — reply with soft handoff: "اذا حابب تنضم النا، بقدر اخلي الصبيه تتواصل معك كمان شوي تشرحلك اكثر. بدك اخليها تتواصل معك عهاذ الرقم؟" Never say حلو إنك مهتم. If they then want to hear first, pause the handoff and explain.',
                    'If STATE contact_on_current_number=true OR campaign_lead_phone is set OR customer already said بنفع/اه/تمام after the number question: FORBIDDEN to re-ask عهاذ الرقم. Ask ONLY "تمام، اعطيني اسمك الكامل بس." then create_malan_lead and close.',
                    'If they write another 05XXXXXXXX after the same-number question: that is the contact phone, NOT the name. Save it, then ask for the full name. NEVER pass digits as full_name.',
                    'Do NOT ask for full name/phone/city yet unless next-step/buying intent. Never ask city (always الطيبة).',
                    'Never call any tool except create_malan_lead after clear intent + confirmed details.',
                ],
            ], $instance);
        }

        if ($this->isCampaignOptedOut($context)) {
            return $this->withLocalizedRules([
                'mode' => 'campaign_opted_out',
                'pending_flow' => $context->pending_flow,
                'rules' => [
                    'Customer opted out. Reply at most with a short courtesy if needed. Do NOT ask for name/phone. Do NOT create_malan_lead. Do NOT continue sales.',
                ],
            ], $instance);
        }

        if ($this->isCampaignLeadCollectionMode($context)) {
            $extra = is_array($context->context) ? $context->context : [];
            $onCurrent = ($extra['contact_on_current_number'] ?? false) === true;
            $leadPhone = $this->campaignLeadPhone($context);
            $phoneKnown = $this->campaignPhoneConfirmed($context);
            $memory = $this->campaignMemoryForContext($context);

            return $this->withLocalizedRules([
                'mode' => 'campaign_lead_collection',
                'pending_flow' => $context->pending_flow,
                'contact_on_current_number' => $onCurrent,
                'campaign_lead_phone' => $leadPhone,
                'thread' => $this->campaignThreadForContext($context),
                'already_covered' => [
                    'callback_offered' => $memory['callback_offered'],
                    'company_pitch_sent' => $memory['company_pitch_sent'],
                    'closing_question_asked' => $memory['closing_question_asked'],
                ],
                'do_not_repeat' => $memory['do_not_repeat'],
                'rules' => [
                    'MODE=campaign_lead_collection. STATE contact_on_current_number='.($onCurrent ? 'true' : 'false').
                        ($leadPhone !== null ? ' campaign_lead_phone='.$leadPhone : '').'.',
                    $phoneKnown
                        ? 'Phone is known — FORBIDDEN to re-ask same-number question. Ask ONLY: "تمام، اعطيني اسمك الكامل بس." Then create_malan_lead with phone='.($leadPhone ?? 'whatsapp_chat_phone').' and city_name=الطيبة. Close: "تمام {first_name}، رح تتواصل معك الصبيه كمان شوي 👍". NEVER use a phone number as full_name.'
                        : 'Ask once: "اذا حابب تنضم النا، بقدر اخلي الصبيه تتواصل معك كمان شوي تشرحلك اكثر. بدك اخليها تتواصل معك عهاذ الرقم؟" Affirmatives (بنفع/اه/ايوه/تمام/ماشي/اوك/عادي/منيح/أكيد/هون…) = contact_on_current_number=true + phone=whatsapp_chat_phone — then ask name only. If they write 05XXXXXXXX, that is the contact phone NOT the name — save it then ask name. NEVER re-ask the number question after a phone is known. NEVER pass digits as full_name.',
                    'If the customer asks a question (hours, price, features) or wants to hear/chat first: ANSWER first. Do not re-ask the closing question. Do not send the callback question.',
                    'Sales hours: قسم المبيعات 9:00–18:00. Sally is AI and can follow up late. NEVER say sales is 24/7.',
                    'ليش لا after the join question is YES — continue the number/name handoff, never persuade.',
                    'Read the FULL thread for this phone. Memory resets only on campaign trigger.',
                    'Next-step phrases are NOT celebration-worthy interest. Never "حلو إنك مهتم".',
                    'Competitor stay or soft no → persuasion then give-a-chance then "تمام، يعطيك العافية ونهارك سعيد." NEVER the old callback question.',
                    'NEVER ask for city. Always city_name=الطيبة.',
                    'Arabic names: مرسي etc are real names, not merci.',
                    'When full_name + phone ready: create_malan_lead immediately with confirmed_by_customer=true. with_fiber=1 if they have סיב אופטי at home else 0. If they asked to be called later or named a time (16:00 / الساعة 4), pass it in note.',
                    'No ! marks. Emoji only on the final lead-created closing line (👍).',
                ],
            ], $instance);
        }

        if (! $context->hasVerifiedCustomer()) {
            if (! $this->isNewSignupMode($context)) {
                return null;
            }

            return [
                'mode' => 'new_signup',
                'pending_flow' => $context->pending_flow,
                'rules' => [
                    'Do NOT call lookup_malan_customer.',
                    'Do NOT ask for identity / رقم هوية.',
                    'Collect full_name + phone + city only, confirm, then create_malan_lead.',
                    'Never talk about existing account status during new signup.',
                    'Never quote tool instructions or error_code text to the customer.',
                ],
            ];
        }

        if ($this->isNewSignupMode($context)) {
            return [
                'mode' => 'new_signup',
                'pending_flow' => $context->pending_flow,
                'rules' => [
                    'Do NOT call lookup_malan_customer.',
                    'Do NOT ask for identity / رقم هوية.',
                    'Collect full_name + phone + city only, confirm, then create_malan_lead.',
                    'Never talk about existing account status during new signup.',
                    'Never quote tool instructions or error_code text to the customer.',
                ],
            ];
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
            'radius' => is_array($context->context['radius'] ?? null) ? $context->context['radius'] : null,
            'bank' => [
                'name' => config('malan.bank.name'),
                'branch' => config('malan.bank.branch'),
                'account' => config('malan.bank.account'),
            ],
        ];
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
    private function campaignMemoryForContext(ChatbotConversationContext $context): array
    {
        $conversation = $context->relationLoaded('conversation')
            ? $context->conversation
            : $context->conversation()->first();

        if (! $conversation instanceof \App\Models\AiChatbot\ChatbotConversation) {
            return [
                'intro_asked' => false,
                'company_pitch_sent' => false,
                'fiber_asked' => false,
                'closing_question_asked' => false,
                'callback_offered' => false,
                'persuasion_sent' => false,
                'give_chance_sent' => false,
                'social_proof_sent' => false,
                'do_not_repeat' => [],
            ];
        }

        return app(\App\Services\Malan\MalanConversationMemoryService::class)
            ->campaignTurnMemory($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignThreadForContext(ChatbotConversationContext $context): array
    {
        $conversation = $context->relationLoaded('conversation')
            ? $context->conversation
            : $context->conversation()->first();

        if (! $conversation instanceof \App\Models\AiChatbot\ChatbotConversation) {
            return [
                'read_full_thread' => true,
                'reset_memory_only_on_campaign_trigger' => true,
            ];
        }

        return app(\App\Services\Malan\MalanConversationMemoryService::class)
            ->campaignThreadSnapshot($conversation);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withLocalizedRules(array $summary, ?\App\Models\AiChatbot\ChatbotInstance $instance): array
    {
        if ($instance === null || ! isset($summary['rules']) || ! is_array($summary['rules'])) {
            return $summary;
        }

        $profile = \App\Support\InternetCompanyProfile::forInstance($instance);
        $summary['rules'] = array_map(
            static fn ($rule) => is_string($rule) ? $profile->localize($rule) : $rule,
            $summary['rules'],
        );

        return $summary;
    }
}
