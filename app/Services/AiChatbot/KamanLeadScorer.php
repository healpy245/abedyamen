<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;

/**
 * Internal Kaman POS sales lead scoring stored on conversation metadata.
 * Never shown to the customer.
 */
class KamanLeadScorer
{
    public const STATUS_LOW = 'LOW_INTENT';

    public const STATUS_POSSIBLE = 'POSSIBLE_LEAD';

    public const STATUS_QUALIFIED = 'QUALIFIED_LEAD';

    public const STATUS_HOT = 'HOT_LEAD';

    /**
     * @return array{
     *     lead_score: int,
     *     lead_status: string,
     *     conversation_stage: string,
     *     engagement: string,
     *     time_waster: bool
     * }
     */
    public function refresh(ChatbotConversation $conversation): array
    {
        $snapshot = $this->score($conversation);

        $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $meta['kaman_sales'] = array_merge($snapshot, [
            'last_customer_message_at' => optional($conversation->last_customer_message_at)?->toIso8601String(),
            'last_bot_message_at' => optional($conversation->last_assistant_message_at)?->toIso8601String(),
            'scored_at' => now()->toIso8601String(),
        ]);

        $conversation->forceFill(['metadata' => $meta])->save();

        return $snapshot;
    }

    /**
     * @return array{
     *     lead_score: int,
     *     lead_status: string,
     *     conversation_stage: string,
     *     engagement: string,
     *     time_waster: bool
     * }
     */
    public function score(ChatbotConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'message']);

        $customer = $messages->where('role', 'user')->pluck('message')->implode("\n");
        $assistant = $messages->where('role', 'assistant')->pluck('message')->implode("\n");
        $all = $customer."\n".$assistant;
        $userCount = $messages->where('role', 'user')->count();

        $score = 0;
        $hasRestaurant = $this->matches($customer, 'مطعم|مطاعم|كاشير|فرع|فروع|معريخت|مطبخ|كشك|برجر|شاورما');
        if ($hasRestaurant) {
            $score += 25;
        }

        $multiBranch = $this->matches($customer, 'فروع|اكثر من فرع|أكثر من فرع|مطاعم|فرعين');
        if ($multiBranch) {
            $score += 15;
        }
        if ($this->matches($customer, '3 فروع|ثلاث فروع|3 مطاعم|ثلاث مطاعم|[4-9]\s*(فرع|مطاعم)|اربع|أربع|خمس')) {
            $score += 10;
        }

        if ($this->matches($customer, 'linkobot|لينكوبوت|לינקובוט|becom|بيكوم|פיקוו|tabit|تابيت|טאביט|aviv|افيف|אביב|t\s*bon|تي بون|טי בון')) {
            $score += 15;
        }
        if ($this->matches($customer, 'فرق|المقارنة|شو الفرق|ليش عندكم')) {
            $score += 15;
        }
        if ($this->matches($customer, 'سعر|قديش|تكلفة|شهري|299')) {
            $score += 15;
        }
        if ($this->matches($customer, 'هات|haat|האט')) {
            $score += 15;
        }
        if ($this->matches($all, 'daas|دآس') || ($this->matches($customer, 'هات|haat') && $this->matches($customer, 'شليح|عمولة|توصيل'))) {
            $score += 15;
        }
        if ($this->matches($customer, 'مشكلة|زيكوي|لخبط|مش شغال|سيء|مضايق|تعبان')) {
            $score += 20;
        }
        if ($this->matches($customer, 'أغير|اغير|بدي أغير|بدي اغير|بفكر اغير|بفكر أغير|بدل المعريخت|بدي نظام')) {
            $score += 20;
        }
        if ($this->matches($customer, 'مندوب|ديمو|يحكي معي|يتواصل|demo')) {
            $score += 20;
        }
        if ($this->matches($customer, 'سمعت عنكم|شفت النظام|مطعم .+ (قال|حكوا|مبسوط)')) {
            $score += 10;
        }
        if ($userCount >= 4) {
            $score += 10;
        }

        $score = min(100, $score);

        $engagement = $this->engagement($customer, $userCount);
        $timeWaster = ! $hasRestaurant && $userCount >= 3 && $score < 20;
        $handoffRequested = $this->matches($assistant, 'بخلي واحد|الشباب المختصين|يتواصلوا معك')
            && $this->matches($customer, 'اه|أيوا|ايوا|تمام|ماشي|موافق|مناسب|يب');
        $handedOff = $this->matches($assistant, 'عهاذ الرقم|بأقرب فرصة|باقرب فرصة');

        $status = match (true) {
            $score >= 71 => self::STATUS_HOT,
            $score >= 51 => self::STATUS_QUALIFIED,
            $score >= 26 => self::STATUS_POSSIBLE,
            default => self::STATUS_LOW,
        };

        $stage = match (true) {
            $timeWaster => 'NOT_RELEVANT',
            $handedOff => 'HANDED_OFF',
            $handoffRequested => 'HANDOFF_REQUESTED',
            $status === self::STATUS_HOT => 'HOT',
            $status === self::STATUS_QUALIFIED => 'QUALIFIED',
            $status === self::STATUS_POSSIBLE => 'INTERESTED',
            $hasRestaurant => 'DISCOVERY',
            default => 'NEW',
        };

        return [
            'lead_score' => $score,
            'lead_status' => $status,
            'conversation_stage' => $stage,
            'engagement' => $engagement,
            'time_waster' => $timeWaster,
        ];
    }

    private function matches(string $text, string $pattern): bool
    {
        return (bool) preg_match('/'.$pattern.'/iu', $text);
    }

    private function engagement(string $customer, int $userCount): string
    {
        $last = trim((string) collect(preg_split("/\n+/u", $customer) ?: [])->last());
        if ($last === '') {
            return 'NO_RESPONSE';
        }
        if (preg_match('/^(اه|آه|طيب|بعدين|اوك|ok)\.?$/iu', $last) && $userCount >= 2) {
            return 'LOW';
        }
        if (mb_strlen($last) >= 40 || str_contains($last, '؟') || str_contains($last, '?') || $userCount >= 3) {
            return 'HIGH';
        }

        return 'MEDIUM';
    }
}
