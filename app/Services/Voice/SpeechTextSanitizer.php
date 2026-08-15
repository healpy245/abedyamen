<?php

declare(strict_types=1);

namespace App\Services\Voice;

/**
 * Prepare assistant text for Arabic neural TTS (Telnyx Bayan, etc.).
 * Bayan cannot pronounce Hebrew script, so Hebrew is mapped to spoken Arabic.
 */
class SpeechTextSanitizer
{
    /**
     * Longest-first phrase replacements (Hebrew → clear Arabic speech).
     *
     * @var array<string, string>
     */
    private const HEBREW_PHRASES = [
        'מספר אסמכתא' => 'رقم الإيصال',
        'מספר אסמכתה' => 'رقم الإيصال',
        'מס׳ אסמכתא' => 'رقم الإيصال',
        'מס\' אסמכתא' => 'رقم الإيصال',
        'מס אסמכתא' => 'رقم الإيصال',
        'מספר חשבון' => 'رقم الحساب',
        'העברה בנקאית' => 'تحويل بنكي',
        'כרטיס אשראי' => 'بطاقة فيزا',
        'תעודת זהות' => 'رقم الهوية',
        'אסמכתא' => 'رقم الإيصال',
        'אסמכתה' => 'رقم الإيصال',
        'תמיכה טכנית' => 'الدعم الفني',
        'תמיכה הטיכנית' => 'الدعم الفني',
        'פורטל לקוח' => 'بوابة الزبون',
        'חשבון בנק' => 'حساب بنك',
        'סניף' => 'فرع',
        'קבלה' => 'وصل',
        'יתרה' => 'رصيد',
        'חוב' => 'دين',
        'ת.ז.' => 'رقم الهوية',
        'ת״ז' => 'رقم الهوية',
        'ת"ז' => 'رقم الهوية',
    ];

    /**
     * Approximate Palestinian/Levantine reading of leftover Hebrew letters.
     *
     * @var array<string, string>
     */
    private const HEBREW_LETTERS = [
        'א' => 'ا',
        'ב' => 'ب',
        'ג' => 'ج',
        'ד' => 'د',
        'ה' => 'ه',
        'ו' => 'و',
        'ז' => 'ز',
        'ח' => 'ح',
        'ט' => 'ط',
        'י' => 'ي',
        'כ' => 'خ',
        'ך' => 'خ',
        'ל' => 'ل',
        'מ' => 'م',
        'ם' => 'م',
        'נ' => 'ن',
        'ן' => 'ن',
        'ס' => 'س',
        'ע' => 'ع',
        'פ' => 'ف',
        'ף' => 'ف',
        'צ' => 'تس',
        'ץ' => 'تس',
        'ק' => 'ك',
        'ר' => 'ر',
        'ש' => 'ش',
        'ת' => 'ت',
        'ּ' => '',
        'ָ' => 'ا',
        'ַ' => 'ا',
        'ֶ' => 'ي',
        'ֵ' => 'ي',
        'ִ' => 'ي',
        'ֹ' => 'و',
        'ֻ' => 'و',
        'ְ' => '',
        'ׂ' => '',
        'ׁ' => '',
    ];

    public function sanitize(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/```[\s\S]*?```/', ' ', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = $this->removeEmojis($text);
        $text = preg_replace('/:[a-z0-9_+\-]{2,}:/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        $text = $this->normalizeHebrewForArabicSpeech($text);
        $text = $this->reduceFillers($text);

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeHebrewForArabicSpeech(string $text): string
    {
        if (! preg_match('/\p{Hebrew}/u', $text)) {
            return $text;
        }

        $phrases = self::HEBREW_PHRASES;
        uksort($phrases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($phrases as $hebrew => $arabic) {
            $text = str_replace($hebrew, $arabic, $text);
        }

        // Transliterate any remaining Hebrew letter runs so Bayan can speak them.
        $text = preg_replace_callback('/\p{Hebrew}+(?:\s+\p{Hebrew}+)*/u', function (array $m): string {
            $chunk = $m[0];
            $out = '';
            $len = mb_strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $ch = mb_substr($chunk, $i, 1);
                if ($ch === ' ' || $ch === "\t") {
                    $out .= ' ';
                    continue;
                }
                $out .= self::HEBREW_LETTERS[$ch] ?? '';
            }

            $out = trim(preg_replace('/\s+/u', ' ', $out) ?? $out);

            return $out !== '' ? $out : ' ';
        }, $text) ?? $text;

        return $text;
    }

    private function reduceFillers(string $text): string
    {
        // Collapse elongated hesitation: إيييي، آآآ، ااا، مممم، ههه
        $text = preg_replace('/إ[يي]{2,}/u', 'إيه', $text) ?? $text;
        $text = preg_replace('/ا[يي]{3,}/u', 'اي', $text) ?? $text;
        $text = preg_replace('/آ{2,}/u', 'آه', $text) ?? $text;
        $text = preg_replace('/ا{3,}/u', 'ا', $text) ?? $text;
        $text = preg_replace('/ه{3,}/u', 'ه', $text) ?? $text;
        $text = preg_replace('/م{3,}/u', 'مم', $text) ?? $text;
        $text = preg_replace('/و{3,}/u', 'و', $text) ?? $text;

        // Soften repeated standalone fillers at clause starts.
        $text = preg_replace('/(?:^|[\s،,])(?:إيه|آه|مم|أوف)(?:\s*[,،])+\s*/u', ' ', $text) ?? $text;

        return $text;
    }

    private function removeEmojis(string $text): string
    {
        $patterns = [
            '/\p{Extended_Pictographic}/u',
            '/[\x{1F3FB}-\x{1F3FF}\x{1F9B0}-\x{1F9B3}]/u',
            '/[\x{1F1E0}-\x{1F1FF}]/u',
            '/[\x{2600}-\x{26FF}]/u',
            '/[\x{2700}-\x{27BF}]/u',
            '/[\x{FE00}-\x{FE0F}]/u',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        return $text;
    }
}
