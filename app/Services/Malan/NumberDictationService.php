<?php

declare(strict_types=1);

namespace App\Services\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;

/**
 * Track spoken/typed digit chunks while a customer dictates a phone (10) or identity (9).
 * Returns short backchannel acks ("تمام، بعدو…") until the expected length is reached.
 */
class NumberDictationService
{
    private const CONTEXT_KEY = 'digit_dictation';

    private const PHONE_LEN = 10;

    private const IDENTITY_LEN = 9;

    /** @var list<string> */
    private const ACKS = [
        'تمام، بعدو…',
        'ماشي، كمّل…',
        'سمعة، كمّل…',
        'سجّلت، بعدو…',
        'أوك، كمّل الأرقام…',
    ];

    public function __construct(
        protected MalanConversationContextService $contextService,
    ) {}

    /**
     * @return array{
     *     status:'none'|'incomplete'|'complete'|'reset',
     *     reply:?string,
     *     digits:?string,
     *     kind:?string,
     *     remaining:?int
     * }
     */
    public function ingest(
        ChatbotConversation $conversation,
        ChatbotInstance $instance,
        string $message,
    ): array {
        if (! $instance->hasMalanIntegration()) {
            return $this->none();
        }

        $state = $this->loadState($conversation, $instance);
        $text = trim($message);

        if ($this->isResetRequest($text)) {
            $this->clearState($conversation, $instance);
            return [
                'status' => 'reset',
                'reply' => 'ماشي، قول الرقم من أول وجديد.',
                'digits' => null,
                'kind' => null,
                'remaining' => null,
            ];
        }

        $chunk = $this->extractDigits($text);
        $active = is_string($state['digits'] ?? null) && $state['digits'] !== '';

        if ($chunk === '' && ! $active) {
            return $this->none();
        }

        if ($chunk === '' && $active) {
            // Customer paused without new digits — gentle nudge only if message is empty-ish/ack.
            if ($this->looksLikePauseOrFiller($text)) {
                return [
                    'status' => 'incomplete',
                    'reply' => 'ماشي، كمّل باقي الأرقام…',
                    'digits' => (string) $state['digits'],
                    'kind' => $this->detectKind((string) $state['digits']),
                    'remaining' => $this->remaining((string) $state['digits']),
                ];
            }

            // Other conversational text while buffering — don't steal the turn.
            return $this->none();
        }

        if (! $active && ! $this->looksLikeDigitDictation($text, $chunk)) {
            return $this->none();
        }

        $digits = $active ? ((string) $state['digits']).$chunk : $chunk;
        $digits = preg_replace('/\D+/', '', $digits) ?? $digits;
        // Chrome STT often adds an extra leading 0 before Israeli mobiles (0053… → 053…).
        if (preg_match('/^005\d/', $digits) === 1) {
            $digits = substr($digits, 1);
        }
        // Hard cap to avoid runaway buffers.
        $digits = substr($digits, 0, 12);

        $kind = $this->detectKind($digits);
        $needed = $this->neededLength($kind);

        if ($needed !== null && strlen($digits) >= $needed) {
            $final = substr($digits, 0, $needed);
            $this->clearState($conversation, $instance);

            return [
                'status' => 'complete',
                'reply' => null,
                'digits' => $final,
                'kind' => $kind ?? (str_starts_with($final, '05') ? 'phone' : 'identity'),
                'remaining' => 0,
            ];
        }

        $this->saveState($conversation, $instance, [
            'digits' => $digits,
            'kind' => $kind,
            'updated_at' => now()->toIso8601String(),
        ]);

        return [
            'status' => 'incomplete',
            'reply' => $this->pickAck($digits),
            'digits' => $digits,
            'kind' => $kind,
            'remaining' => $this->remaining($digits),
        ];
    }

    /**
     * @return array{status:'none',reply:null,digits:null,kind:null,remaining:null}
     */
    private function none(): array
    {
        return [
            'status' => 'none',
            'reply' => null,
            'digits' => null,
            'kind' => null,
            'remaining' => null,
        ];
    }

    public function extractDigits(string $text): string
    {
        $normalized = $this->normalizeEasternDigits($text);
        $normalized = mb_strtolower($normalized);

        // Prefer explicit digit characters first.
        $digitChars = preg_replace('/\D+/', '', $normalized) ?? '';

        // Parse Arabic/Hebrew/English number words in order of appearance.
        $wordDigits = $this->extractNumberWords($normalized);

        // If both exist, prefer the longer useful stream (spoken words often replace typed digits).
        if ($wordDigits !== '' && strlen($wordDigits) >= strlen($digitChars)) {
            return $wordDigits;
        }

        return $digitChars;
    }

    private function extractNumberWords(string $text): string
    {
        // Replace common separators so tokens split cleanly.
        $text = str_replace(['-', '_', '/', '\\', ',', '،', '.', ':', ';'], ' ', $text);

        $map = [
            'صفر' => '0', 'زيرو' => '0', 'زرو' => '0', 'zero' => '0',
            'واحد' => '1', 'وحد' => '1', 'واحدة' => '1', 'one' => '1',
            'اتنين' => '2', 'اثنين' => '2', 'ثنين' => '2', 'اثنان' => '2', 'تنين' => '2', 'two' => '2',
            'ثلاثة' => '3', 'تلاتة' => '3', 'تلاته' => '3', 'ثلاث' => '3', 'تلات' => '3', 'three' => '3',
            'اربعة' => '4', 'أربعة' => '4', 'اربعة' => '4', 'اربعة.' => '4', 'اربعه' => '4', 'أربعه' => '4', 'four' => '4',
            'خمسة' => '5', 'خمسه' => '5', 'خمس' => '5', 'five' => '5',
            'ستة' => '6', 'سته' => '6', 'ست' => '6', 'six' => '6',
            'سبعة' => '7', 'سبعه' => '7', 'سبع' => '7', 'seven' => '7',
            'ثمانية' => '8', 'ثمانيه' => '8', 'تمانية' => '8', 'تمانيه' => '8', 'ثمان' => '8', 'تمان' => '8', 'eight' => '8',
            'تسعة' => '9', 'تسعه' => '9', 'تسع' => '9', 'nine' => '9',
            // Hebrew spoken digits (common in mixed speech)
            'אפס' => '0', 'אחד' => '1', 'שתיים' => '2', 'שנים' => '2', 'שלוש' => '3',
            'ארבע' => '4', 'חמש' => '5', 'שש' => '6', 'שבע' => '7', 'שמונה' => '8', 'תשע' => '9',
        ];

        // Longest keys first.
        uksort($map, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $out = '';
        $tokens = preg_split('/\s+/u', $text) ?: [];
        $repeatMarkers = [
            'مرتين', 'مرّتين', 'مرتين.', 'ثنتين', 'مرتين،',
            'twice', 'double', 'دبل', 'دبلة',
            'פעמיים',
        ];

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $tokenKey = mb_strtolower(rtrim($token, '.,،;:'));
            if (in_array($tokenKey, $repeatMarkers, true)) {
                // "خمسة مرتين" / "5 פעמיים" → repeat last captured digit.
                if ($out !== '') {
                    $out .= substr($out, -1);
                }
                continue;
            }

            if (preg_match('/^\d+$/', $token)) {
                $out .= $token;
                continue;
            }
            foreach ($map as $word => $digit) {
                if ($token === $word || str_starts_with($token, $word)) {
                    $out .= $digit;
                    break;
                }
            }
        }

        return $out;
    }

    private function normalizeEasternDigits(string $text): string
    {
        $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($eastern, $western, $text);
    }

    private function detectKind(string $digits): ?string
    {
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '05')) {
            return 'phone';
        }

        // Still only "0" — wait for next digit to decide.
        if ($digits === '0') {
            return null;
        }

        // User rule: not starting with 05 ⇒ identity.
        return 'identity';
    }

    private function neededLength(?string $kind): ?int
    {
        return match ($kind) {
            'phone' => self::PHONE_LEN,
            'identity' => self::IDENTITY_LEN,
            default => null,
        };
    }

    private function remaining(string $digits): ?int
    {
        $kind = $this->detectKind($digits);
        $needed = $this->neededLength($kind);
        if ($needed === null) {
            return null;
        }

        return max(0, $needed - strlen($digits));
    }

    private function pickAck(string $digits): string
    {
        $idx = strlen($digits) % count(self::ACKS);

        return self::ACKS[$idx];
    }

    private function looksLikeDigitDictation(string $text, string $chunk): bool
    {
        if ($chunk === '') {
            return false;
        }

        // Full number pasted/typed at once.
        if (strlen($chunk) >= 3 && preg_match('/^\d+$/', trim($this->normalizeEasternDigits($text)))) {
            return true;
        }

        // Mostly number words / digits with little else.
        $stripped = preg_replace('/[\d\s\-_,.،:]+/u', '', $this->normalizeEasternDigits($text)) ?? '';
        $stripped = preg_replace('/(صفر|زيرو|واحد|اتنين|اثنين|ثنين|ثلاثة|تلاتة|اربعة|أربعة|خمسة|ستة|سبعة|ثمانية|تمانية|تسعة|أوك|تمام|رقم|هاتفي|هويتي|الي|هي|هو)+/iu', '', $stripped) ?? $stripped;
        $stripped = trim($stripped);

        if (strlen($chunk) >= 3) {
            return mb_strlen($stripped) <= 8;
        }

        // Short chunk (1–2 digits): only if message is essentially the digits/words.
        return mb_strlen($stripped) <= 2;
    }

    private function looksLikePauseOrFiller(string $text): bool
    {
        $t = trim(mb_strtolower($text));
        if ($t === '' || $t === '...' || $t === '…') {
            return true;
        }

        return (bool) preg_match('/^(تمام|ماشي|أوك|ok|اه|آه|هيك|كمّل|كمل)[.!…]*$/iu', $t);
    }

    private function isResetRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/(غلط|من الأول|من اول|امسح|صفّر|صفرها|ابدأ من جديد|ابدأ من اول|reset|cancel)/iu', $t)
            && ! preg_match('/\d/u', $t);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(ChatbotConversation $conversation, ChatbotInstance $instance): array
    {
        $context = $this->contextService->getOrCreate($conversation, $instance);
        $bag = is_array($context->context) ? $context->context : [];
        $state = is_array($bag[self::CONTEXT_KEY] ?? null) ? $bag[self::CONTEXT_KEY] : [];

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function saveState(ChatbotConversation $conversation, ChatbotInstance $instance, array $state): void
    {
        $context = $this->contextService->getOrCreate($conversation, $instance);
        $bag = is_array($context->context) ? $context->context : [];
        $bag[self::CONTEXT_KEY] = $state;
        $context->context = $bag;
        if ($context->expires_at === null) {
            $context->expires_at = now()->addHours((int) config('malan.verified_context_ttl_hours', 24));
        }
        $context->save();
    }

    private function clearState(ChatbotConversation $conversation, ChatbotInstance $instance): void
    {
        $context = $this->contextService->getOrCreate($conversation, $instance);
        $bag = is_array($context->context) ? $context->context : [];
        unset($bag[self::CONTEXT_KEY]);
        $context->context = $bag;
        $context->save();
    }
}
