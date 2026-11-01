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

    private const MIN_MESSAGES_BEFORE_CLOSING_DETAILS = 2;

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
     * An engaged customer who has not been asked the closing details yet goes to the AI
     * (prompt section V2.11a) instead of getting the canned thanks reply.
     */
    public function shouldAskClosingDetails(ChatbotConversation $conversation, ?int $beforeMessageId = null): bool
    {
        $priorCustomerMessages = $conversation->messages()
            ->where('role', 'user')
            ->when($beforeMessageId !== null, fn ($query) => $query->where('id', '<', $beforeMessageId))
            ->count();
        if ($priorCustomerMessages < self::MIN_MESSAGES_BEFORE_CLOSING_DETAILS) {
            return false;
        }

        return ! $conversation->messages()
            ->where('role', 'assistant')
            ->pluck('message')
            ->contains(fn (?string $message): bool => preg_match(self::CLOSING_DETAILS_ASKED, (string) $message) === 1);
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
