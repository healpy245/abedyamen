<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Campaign WhatsApp copy must address the customer as male.
 * Sally (the bot) and «الصبيه» stay feminine.
 */
final class MasculineCustomerAddress
{
    /**
     * Feminine 2nd-person forms → masculine. Longest keys first.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'بنعطيكي' => 'بنعطيك',
        'بتكملي' => 'بتكمل',
        'بتكوني' => 'بتكون',
        'بتقدري' => 'بتقدر',
        'بتعملي' => 'بتعمل',
        'بتحبي' => 'بتحب',
        'تحبي' => 'تحب',
        'تكملي' => 'تكمل',
        'تسمعي' => 'تسمع',
        'تكوني' => 'تكون',
        'تقدري' => 'تقدر',
        'تعملي' => 'تعمل',
        'تبدلي' => 'تبدل',
        'تنبسطي' => 'تنبسط',
        'تشتركي' => 'تشترك',
        'تنضمي' => 'تنضم',
        'تاخدي' => 'تاخذ',
        'تجربي' => 'تجرب',
        'تفكري' => 'تفكر',
        'تسألي' => 'تسأل',
        'تسجلي' => 'تسجل',
        'خليكي' => 'خليك',
        'عليكي' => 'عليك',
        'فيكي' => 'فيك',
        'إنتي' => 'إنت',
        'انتي' => 'انت',
        'حابة' => 'حابب',
        'حابه' => 'حابب',
        'مهتمة' => 'مهتم',
        'مهتمه' => 'مهتم',
        'فاضية' => 'فاضي',
        'فاضيه' => 'فاضي',
        'احكيلكِ' => 'احكيلك',
    ];

    public static function sanitize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        foreach (self::REPLACEMENTS as $feminine => $masculine) {
            // Use letters only as word edges so Arabic comma/punctuation still match.
            $text = preg_replace(
                '/(?<!\p{L})'.preg_quote($feminine, '/').'(?!\p{L})/u',
                $masculine,
                $text,
            ) ?? $text;
        }

        return $text;
    }
}
