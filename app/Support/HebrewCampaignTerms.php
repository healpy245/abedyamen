<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Force campaign product terms to full Hebrew when the model mixes Arabic letters in.
 *
 * Dialect Arabic like «بيزك» is left alone when the whole word is Arabic.
 */
final class HebrewCampaignTerms
{
    /**
     * Arabic letters that look like Hebrew when the model mixes scripts inside one word.
     *
     * @var array<string, string>
     */
    private const ARABIC_TO_HEBREW = [
        'ا' => 'א', 'أ' => 'א', 'إ' => 'א', 'آ' => 'א',
        'ب' => 'ב',
        'ج' => 'ג', 'گ' => 'ג',
        'د' => 'ד',
        'ه' => 'ה', 'ة' => 'ה',
        'و' => 'ו',
        'ز' => 'ז',
        'ح' => 'ח',
        'ط' => 'ט', 'ت' => 'ט',
        'ي' => 'י', 'ى' => 'י', 'ی' => 'י',
        'ك' => 'כ', 'ک' => 'כ',
        'ل' => 'ל',
        'م' => 'מ',
        'ن' => 'נ',
        'س' => 'ס',
        'ع' => 'ע',
        'ف' => 'פ',
        'ص' => 'צ',
        'ق' => 'ק',
        'ر' => 'ר',
        'ش' => 'ש',
    ];

    public static function sanitize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = self::replaceKnownPhrases($text);
        $text = self::rewriteMixedScriptTokens($text);
        $text = self::replaceKnownPhrases($text);

        return $text;
    }

    private static function replaceKnownPhrases(string $text): string
    {
        $magdil = '[מمм]\s*[גجگ]\s*[דد]\s*[יىيی]\s*[לل]';
        $tvach = '[טتط]\s*[וو]\s*[וو]\s*[חחح]';

        $text = preg_replace('/'.$magdil.'\s+'.$tvach.'/u', 'מגדיל טווח', $text) ?? $text;
        $text = preg_replace('/'.$magdil.$tvach.'/u', 'מגדיל טווח', $text) ?? $text;
        $text = preg_replace('/'.$magdil.'/u', 'מגדיל', $text) ?? $text;
        $text = preg_replace('/'.$tvach.'/u', 'טווח', $text) ?? $text;

        $sib = '[סس]\s*[יىيی]\s*[בب]';
        $opti = '[אاأ]\s*[וو]\s*[פف]\s*[טتط]\s*[יىيی]';
        $text = preg_replace('/'.$sib.'\s+'.$opti.'/u', 'סיב אופטי', $text) ?? $text;

        $tashtit = '[תتط]\s*[שش]\s*[תتط]\s*[יىيی]\s*[תتط]';
        $text = preg_replace('/'.$tashtit.'/u', 'תשתית', $text) ?? $text;

        // Dialect بيزك with Hebrew ק mixed in (بيزק) → full Arabic.
        $text = preg_replace('/ب[يىیי]\s*ز\s*[קכ]/u', 'بيزك', $text) ?? $text;

        // Mixed-script 3-letter Bezeq → full Hebrew בזק. Leave dialect بيزك (all Arabic).
        $text = preg_replace('/(?<!\p{Arabic})[בب]\s*[זز]\s*[קقك](?!\p{Arabic})/u', 'בזק', $text) ?? $text;
        $text = preg_replace('/بזק|בזك|בזق|بזك/u', 'בזק', $text) ?? $text;

        return $text;
    }

    private static function rewriteMixedScriptTokens(string $text): string
    {
        return preg_replace_callback('/[\p{Arabic}\p{Hebrew}]+/u', static function (array $match): string {
            $token = $match[0];
            $hasArabic = (bool) preg_match('/\p{Arabic}/u', $token);
            $hasHebrew = (bool) preg_match('/\p{Hebrew}/u', $token);
            if (! $hasArabic || ! $hasHebrew) {
                return $token;
            }

            $hebrew = strtr($token, self::ARABIC_TO_HEBREW);
            $hebrew = preg_replace('/[^\p{Hebrew}]/u', '', $hebrew) ?? $hebrew;

            return match ($hebrew) {
                'מגדיל' => 'מגדיל',
                'טווח' => 'טווח',
                'בזק' => 'בזק',
                'ביזק' => 'بيزك',
                'סיב' => 'סיב',
                'אופטי' => 'אופטי',
                'תשתית' => 'תשתית',
                'הוט' => 'הוט',
                default => $token,
            };
        }, $text) ?? $text;
    }
}
