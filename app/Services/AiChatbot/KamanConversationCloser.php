<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;

/**
 * Detects WhatsApp wrap-up (thanks / goodbye) so the sales bot can stop pitching.
 */
class KamanConversationCloser
{
    public const THANKS_REPLY_AR = 'العفو';

    public const THANKS_REPLY_HE = 'בכיף';

    public const GOLDEN_CLOSE_AR = 'خلص لكان رح اخزن رقمك عندي, بتواصل معك السوخن (סוכן) تبعنا بالمنطقة عندك وبشرحلك وجه لوجه.';

    public const GOLDEN_CLOSE_HE = 'סגור, אני שומרת את המספר שלך. הסוכן שלנו באזור שלך יחזור אליך ויסביר לך פנים אל פנים.';

    public const GOLDEN_CLOSE_EN = 'Great, I\'ll save your number. Our sales agent in your area will contact you and explain in person.';

    private const MIN_MESSAGES_BEFORE_CLOSING_DETAILS = 2;

    private const SOCHEN_OFFERED = '/سوخن|סוכן|يتواصل|يحكي معك|לקבוע פגישה|sales rep/iu';

    private const CLOSING_DETAILS_ASKED = '/الاسم الكامل|اسمك الكامل|اسمك بالكامل|فاتح|رح يفتح|שם מלא|השם המלא|פתוחה|תיפתח|full name|already open|opening soon/iu';

    public function isThanksClosing(string $text): bool
    {
        $stripped = $this->stripStickerLabels($text);
        if ($stripped === '') {
            return false;
        }

        if ($this->looksLikeQuestionOrNewAsk($stripped)) {
            return false;
        }

        return $this->looksLikeThanks($stripped);
    }

    /**
     * Thanks goes to the AI (prompt section V2.11a) when the sochen was not offered yet, or when
     * it answers the offer and may be an agreement. Otherwise it gets the canned thanks reply.
     */
    public function shouldHandleThanksWithAi(ChatbotConversation $conversation, ?int $beforeMessageId = null): bool
    {
        $priorCustomerMessages = $conversation->messages()
            ->where('role', 'user')
            ->when($beforeMessageId !== null, fn ($query) => $query->where('id', '<', $beforeMessageId))
            ->count();
        if ($priorCustomerMessages < self::MIN_MESSAGES_BEFORE_CLOSING_DETAILS) {
            return false;
        }

        $replies = $conversation->messages()
            ->where('role', 'assistant')
            ->orderBy('id')
            ->pluck('message')
            ->map(fn (?string $message): string => (string) $message);

        if ($replies->contains(fn (string $message): bool => preg_match(self::CLOSING_DETAILS_ASKED, $message) === 1)) {
            return false;
        }

        $offered = fn (string $message): bool => preg_match(self::SOCHEN_OFFERED, $message) === 1;

        return ! $replies->contains($offered) || $offered((string) $replies->last());
    }

    /**
     * A customer who just answered the closing details gets the golden close without the AI,
     * so the model cannot resume selling or offer the sochen again.
     */
    public function answeredClosingDetails(ChatbotConversation $conversation, string $text): bool
    {
        $stripped = $this->stripStickerLabels($text);
        if ($stripped === ''
            || $this->isThanksClosing($text)
            || $this->looksLikeQuestionOrNewAsk($stripped)
            || preg_match('/^(?:لا|لأ|مش|بديش|לא|no)(?:\s|$)/iu', $stripped) === 1) {
            return false;
        }

        $lastReply = (string) $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->value('message');

        return preg_match(self::CLOSING_DETAILS_ASKED, $lastReply) === 1;
    }

    /**
     * The reply that skips the AI for this customer message, if any.
     */
    public function cannedReplyFor(ChatbotConversation $conversation, string $text, ?int $beforeMessageId = null): ?string
    {
        if ($this->isThanksClosing($text)) {
            return $this->shouldHandleThanksWithAi($conversation, $beforeMessageId) ? null : $this->closingReplyFor($text);
        }

        return $this->answeredClosingDetails($conversation, $text) ? $this->goldenCloseFor($text) : null;
    }

    public function containsSticker(string $text): bool
    {
        return str_contains($text, 'ستيكر واتساب');
    }

    public function isStickerOnly(string $text): bool
    {
        return $this->containsSticker($text) && $this->stripStickerLabels($text) === '';
    }

    public function closingReplyFor(string $text): string
    {
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return self::THANKS_REPLY_HE;
        }

        return self::THANKS_REPLY_AR;
    }

    public function goldenCloseFor(string $text): string
    {
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return self::GOLDEN_CLOSE_HE;
        }

        return preg_match('/\p{Arabic}/u', $text) === 1 ? self::GOLDEN_CLOSE_AR : self::GOLDEN_CLOSE_EN;
    }

    private function stripStickerLabels(string $text): string
    {
        $stripped = preg_replace('/\[الزبون أرسل ستيكر واتساب[^\]]*\]/u', ' ', $text) ?? $text;
        $stripped = preg_replace('/رداً على:\s*"[^"]*"/u', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim($stripped);
    }

    private function looksLikeQuestionOrNewAsk(string $text): bool
    {
        if (str_contains($text, '?') || str_contains($text, '؟')) {
            return true;
        }

        return (bool) preg_match(
            '/(?:^|\s)(قديش|كيف|وين|متى|ليش|شو\s|بدي|عندكم|ممكن|سعر|תמונה|כמה|איך)(?:\s|$)/u',
            $text
        );
    }

    private function looksLikeThanks(string $text): bool
    {
        $compact = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $compact = trim(preg_replace('/\s+/u', ' ', $compact) ?? $compact);
        if ($compact === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(?:'
            .'تسلمي|تسلم|يسلمو|يسلموو+|مشكور(?:ة)?|شكرا(?:ً| الك| لك| جزيل[ااً]?)?'
            .'|يعطيك\s*العافية|الله\s*يعافيك|تمام\s*شكرا(?:ً| الك| لك)?'
            .'|ماشي\s*شكرا(?:ً| الك| لك)?|خلاص\s*شكرا(?:ً)?'
            .'|thank(?:s| you)|thx|ty'
            .'|תודה(?:\s*רבה)?'
            .')(?:\s|$)/iu',
            $compact
        );
    }
}
