<?php

declare(strict_types=1);

namespace App\Services\Malan\Campaigns;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Versioning, pending-send invalidation, and burst metadata for campaign chats.
 */
final class CampaignConversationBurstService
{
    public const STATE_WAITING_FOR_CUSTOMER = 'waiting_for_customer';

    public const STATE_GENERATING = 'generating';

    public const STATE_PENDING_SEND = 'pending_send';

    public const STATE_SENT = 'sent';

    public const STATE_STALE = 'stale';

    public const STATE_CANCELLED = 'cancelled';

    /**
     * @return array{version:int,wait_seconds:int,burst_started_at:string}
     */
    public function registerInboundMessage(ChatbotConversation $conversation): array
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $version = ((int) ($meta['campaign_conversation_version'] ?? 0)) + 1;

        $burstStartedRaw = $meta['campaign_burst_started_at'] ?? null;
        $burstOpen = ! empty($meta['campaign_burst_open']);
        $burstStartedAt = ($burstOpen && is_string($burstStartedRaw) && $burstStartedRaw !== '')
            ? Carbon::parse($burstStartedRaw)
            : now();

        $waitSeconds = CampaignListeningWindow::secondsUntilProcess($burstStartedAt);
        $listenUntil = now()->addSeconds($waitSeconds);

        $meta['campaign_conversation_version'] = $version;
        $meta['campaign_burst_open'] = true;
        $meta['campaign_burst_started_at'] = $burstStartedAt->toIso8601String();
        $meta['campaign_last_customer_message_at'] = now()->toIso8601String();
        $meta['campaign_listen_until'] = $listenUntil->toIso8601String();
        $meta['campaign_pending_process_version'] = $version;
        $meta['campaign_response_state'] = self::STATE_WAITING_FOR_CUSTOMER;

        $conversation->forceFill(['metadata' => $meta])->save();

        $this->invalidatePendingOutbound($conversation, $version, 'newer_customer_message');

        return [
            'version' => $version,
            'wait_seconds' => $waitSeconds,
            'burst_started_at' => $burstStartedAt->toIso8601String(),
        ];
    }

    public function currentVersion(ChatbotConversation $conversation): int
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];

        return (int) ($meta['campaign_conversation_version'] ?? 0);
    }

    public function invalidatePendingOutbound(
        ChatbotConversation $conversation,
        int $newVersion,
        string $reason,
    ): int {
        $cancelled = 0;
        $pending = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where(function ($q): void {
                $q->where('delivery_status', 'pending')
                    ->orWhereIn('delivery_status', ['queued', 'sending']);
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($pending as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $state = (string) ($meta['campaign_response_state'] ?? $meta['campaign_delivery'] ?? '');
            if (in_array($state, [self::STATE_SENT, self::STATE_CANCELLED, self::STATE_STALE], true)) {
                continue;
            }
            if (($meta['campaign_delivery'] ?? null) === 'sent' && ! empty($meta['greenapi_id_message'])) {
                continue;
            }
            if (! in_array($state, [
                self::STATE_PENDING_SEND,
                self::STATE_GENERATING,
                'queued',
                '',
            ], true) && ($message->delivery_status !== 'pending')) {
                continue;
            }

            $message->forceFill([
                'delivery_status' => 'failed',
                'metadata' => array_merge($meta, [
                    'campaign_delivery' => self::STATE_CANCELLED,
                    'campaign_response_state' => self::STATE_STALE,
                    'campaign_cancel_reason' => $reason,
                    'campaign_cancelled_by_version' => $newVersion,
                ]),
            ])->save();
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Unprocessed customer messages in the open burst (since last sent assistant, or all recent unprocessed).
     *
     * @return list<ChatbotMessage>
     */
    public function collectUnprocessedBurst(ChatbotConversation $conversation): array
    {
        $lastSentAssistantId = (int) ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where(function ($q): void {
                $q->where('delivery_status', 'sent')
                    ->orWhere('metadata->campaign_response_state', self::STATE_SENT)
                    ->orWhere('metadata->campaign_delivery', 'sent');
            })
            ->orderByDesc('id')
            ->value('id');

        $query = ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderBy('id');

        if ($lastSentAssistantId > 0) {
            $query->where('id', '>', $lastSentAssistantId);
        }

        $messages = $query->get();
        $burst = [];
        foreach ($messages as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (! empty($meta['campaign_burst_processed'])) {
                continue;
            }
            $burst[] = $message;
        }

        return $burst;
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    public function markBurstProcessed(array $burst, int $version): void
    {
        foreach ($burst as $message) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $message->forceFill([
                'metadata' => array_merge($meta, [
                    'campaign_burst_processed' => true,
                    'campaign_burst_processed_version' => $version,
                    'campaign_burst_processed_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }
    }

    public function closeBurst(ChatbotConversation $conversation, int $version): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['campaign_burst_open'] = false;
        $meta['campaign_last_processed_version'] = $version;
        unset($meta['campaign_listen_until'], $meta['campaign_pending_process_version']);
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    public function setResponseState(ChatbotConversation $conversation, string $state): void
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['campaign_response_state'] = $state;
        $conversation->forceFill(['metadata' => $meta])->save();
    }

    /**
     * @param  list<ChatbotMessage>  $burst
     */
    public function buildBurstEphemeralPrompt(array $burst, ?\App\Models\AiChatbot\ChatbotInstance $instance = null): string
    {
        $lines = [];
        foreach ($burst as $i => $message) {
            $n = $i + 1;
            $line = trim((string) $message->message);
            $meta = is_array($message->metadata) ? $message->metadata : [];
            if (($meta['whatsapp_voice'] ?? false) === true) {
                $line = '[Voice note transcribed — speech-to-text, may contain wrong words] '.$line;
            }
            $lines[] = "{$n}. ".$line;
        }
        $joined = implode("\n", $lines);
        $count = count($burst);

        $text = <<<TEXT
[CAMPAIGN_BURST]
The customer just sent {$count} WhatsApp message(s) in one burst (read ALL of them together before replying):

{$joined}

Instructions for this turn:
- Treat the burst as ONE thought. Do NOT answer only the last line.
- Internally identify: customer_intent, main_pain_point, questions, objections, buying_signals, relevant_campaign_facts.
- Focus the reply on what actually matters in this burst (e.g. price if they said quality is fine but price is high).
- Read the FULL chat first. NEVER repeat a sentence you already sent. If intro/pitch/closing/callback already appeared above, do NOT send them again.
- Reply once in natural spoken Palestinian/Arab-Israeli WhatsApp Arabic (NO فصحى, NO chatbot filler). Dialect must stay Palestinian for the WHOLE chat — never drift into MSA mid-conversation.
- NEVER use exclamation marks (!) in any customer-facing message. No soft CTAs like «إذا عندك أي استفسار… خبرني!».
- Dialect swaps (mandatory): فكر في الأمر/فكر بالأمر → فكر بلاشي; نعمل طول الأسبوع → بنشتغل وبنعطي خدمة طول الأسبوع; بدون انقطاعات/انقطاعات → بدون تقطيعات/تقطيعات.
- Address the customer as MALE always (تحب / تكمل / حابب / بدك). NEVER feminine 2nd person (تحبي / تكملي / حابة / تكوني). الصبيه stays feminine (تشرحلك / تتواصل).
- Hebrew product terms: ALWAYS write exactly מגדיל טווח, בזק, and סיב אופטי (full Hebrew only). Never mix Arabic letters into them (no مגדיל / بזק / بيزק). Dialect بيزك is OK only when the whole word is Arabic (ك not ק).
- First greeting ONLY if you have not yet sent "اكيد بتعرفنا صح؟" in this chat: ONE bubble — "انا سالي من شركة ملان، اكيد بتعرفنا صح؟" — then WAIT. If they later ask "انت ذكاء اصطناعي؟": answer honestly — اه أنا ذكاء اصطناعي بتابع مع الزباين حتى بأوقات متأخرة. قسم المبيعات من 9 الصبح لـ6 المسا. NEVER restart the intro.
- If they know Malan (اه/اكيد/بعرف/سمعت عنكم/نعم) AND pitch not yet sent: short "مثل ما بتعرف، ملان انترنت الشركة العربية الاولى في المنطقة لتقديم خدمات الانترنت السريع סיב אופטי." then AUTO short Taybee line "بعد نجاح رهيب في 5 بلدات خلال فترة قياسية يسعدنا ان تكون الطيبة البلد السادس" then the fiber question. NO Instagram/solutions bubble here. NO "مش رح اوخذ من وقتك". NEVER ask the join question before fiber.
- If they do NOT know Malan AND pitch not yet sent: exactly 3 bubbles STRICT order — (1) "ملان انترنت الشركة العربية الاولى في المنطقة لتقديم خدمات الانترنت السريع סיב אופטי" (2) ONLY "بعد نجاح رهيب في 5 بلدات خلال فترة قياسية يسعدنا ان تكون الطيبة البلد السادس" (3) "عندكم סיב אופטי بالبيت ولا لسه؟" then STOP and wait. FORBIDDEN in this turn: Instagram link, "لتقديم حلول", "الزباين مبسوطه", "حابب تكون جزء من نجاحنا".
- Mid-chat social proof (ONCE, never in the intro): if they ask how other customers feel (شو راي الزباين / اللكوحوت / مبسوطين / آراء) OR want to hear more (بدي اسمع / احكيلي / شو بتقدمو / شرح) AND the Instagram/solutions bubble was not sent yet, send exactly: "لتقديم حلول و خدمات انترنت متطورة / سرعات عالية و اسعار مناسبة. دعم تقني على مدار الساعة طيلة ايام الاسبوع. طبعا احنا شركه حاصله على رخص من משרד התקשורת وبنشتغل بمستوى حرفي جدا جدا. الزباين مبسوطه واصحاب المصالح مكيفين وهاي احد الفيديوهات من اراء الزباين بخدمتنا https://www.instagram.com/reel/DOs3eKXDNa7/?igsi=YWJkd3M3aWllZDRm" — full link, never shortened. If already sent, do not resend.
- After fiber answer: if YES continue with "حابب تكون جزء من نجاحنا بالطيبة؟" (must keep ؟). If NO: two bubbles then join — (1) "تمام، התקנה סיב אופטי وتركيب بشكل عام وبباقي الشركات مثل بيزك بتكون تكلفة بين 300-500 شيقل. احنا عاملين حملة لأول 200 زبون بالطيبة تركيب مجاني تماما." (2) "وأول 3 شهور بس 29 شيقل. بعدين 149 ثابت مدى الحياة." then join question. FORBIDDEN on this turn: switching companies / our תשתית / واثقين. That pitch is ONLY after a negative reply.
- After the join question, «ليش لا» / يلا / اكيد / تمام / ماشي / فش مشكلة = YES they want to join. Reply with the same-number ask. NEVER persuasion. NEVER treat ليش لا as لا.
- Read the FULL chat for this phone from first message to last. Do not restart the intro. Memory is wiped ONLY when staff triggers a resend to this number.
- Competitor stay or soft no (بيزك / בזק / Bezeq / هوت / HOT / הוט / another ISP / مرتاح / بلزمش / لا بديش اشترك): NOT a callback. First: "فاهمك. استغل السعر الي عاملينه، أول 3 شهور بس 29 شيقل، وהתקנת סיב אופטי المجانية، بالآخر بعد وقت لو ما عجبتك الخدمة فيك تبدل شركة على نفس התשתית تبعنا." plus weekend/same-day fix. Grammar: تنبسط never ينبسط. Second: give-a-chance. Third: "تمام، يعطيك العافية ونهارك سعيد." NEVER send "بتحب نرجعلك بمكالمه بوقت ثاني؟".
- Hard opt-out (حلي عني / باي / لا تبعثولي): "تمام، يعطيك العافية ونهارك سعيد."
- First refusal (مش حابب / مش مهتم / ما بدي) without a competitor: same persuasion ladder as competitor stay. NEVER the old callback question.
- Next-step questions (وانا شو اعملكم / شو اعمل / وبعدين / شو المطلوب / كيف بنضم): soft handoff same-number ask. NEVER "حلو إنك مهتم". If they then want to hear first, pause the handoff and explain.
- After buying/next-step intent: if contact_on_current_number=false and no campaign_lead_phone yet, ask once "بدك اخليها تتواصل معك عهاذ الرقم؟". Affirmatives (بنفع/اه/…) set contact_on_current_number=true — NEVER re-ask. If they write 05XXXXXXXX, that is the contact phone NOT the name — save it then ask "تمام، اعطيني اسمك الكامل بس." NEVER pass digits as full_name. Then create_malan_lead with with_fiber=1 if they have סיב אופטי at home else 0; note=call-later / 16:00 if they asked. Close "تمام {first_name}، رح تتواصل معك الصبيه كمان شوي 👍".
- If they point to this WhatsApp number: phone=whatsapp_chat_phone when name known.
- No ! marks. Emoji only on final closing 👍. Hebrew always תשתית וספק (no dash).
- Price when asked DIRECTLY: answer immediately WITHOUT «بالمناسبه». Use «بالمناسبه» only when pivoting back to price mid another topic (outage/Bezeq/etc).
- Price + offer wording: بيوخذو (never يوخذو); first 200 get מגדיל טווח free; free install. Short Taybee line is "بعد نجاح رهيب في 5 بلدات… الطيبة البلد السادس" only — Instagram/solutions is mid-chat, not intro.
- After asking for name: treat replies like مرسي as a real Arabic name (Morsi), NOT French merci/thanks.
- Voice-note lines are machine transcripts: read them for intent, never quote them back word for word, and if a line is unintelligible ask "ما فهمت عليك منيح، بتقدر تعيد؟" instead of guessing an answer.
- Separate every bubble with a blank line.
[/CAMPAIGN_BURST]
TEXT;

        return \App\Support\InternetCompanyProfile::forInstance($instance)->localize($text);
    }

    public function burstStartedAt(ChatbotConversation $conversation): ?CarbonInterface
    {
        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $raw = $meta['campaign_burst_started_at'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Carbon::parse($raw);
    }
}
