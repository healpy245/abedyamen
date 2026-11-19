<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds a mobile number a restaurant typed into a ticket and turns it into a WhatsApp link.
 *
 * Local numbers are Israeli or Palestinian mobiles (05x, ten digits). 059 and 056 are Palestinian
 * networks and get +970; the other 05x numbers get +972. Numbers written with + or 00 are kept as
 * they are. Landlines, dates, prices and order numbers are not matched.
 */
final class WhatsAppContact
{
    /** @var list<string> */
    private const PALESTINIAN_PREFIXES = ['059', '056'];

    /**
     * @return array{phone: string, whatsapp: string}|null
     */
    public static function find(?string ...$texts): ?array
    {
        foreach ($texts as $text) {
            $text = (string) $text;
            if ($text === '') {
                continue;
            }
            // A run of digits that may hold spaces, dashes, dots or brackets, with an optional + in front.
            preg_match_all('/(?<![\d+])(?:\+|00)?\(?\d[\d\s().\-]{7,20}\d(?!\d)/u', self::westernDigits($text), $matches);
            foreach ($matches[0] as $candidate) {
                $digits = self::fromCandidate(trim($candidate));
                if ($digits !== null) {
                    return [
                        'phone' => self::display($digits),
                        'whatsapp' => 'https://wa.me/'.$digits,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The whole run first; then runs of its space-separated parts, so "0501234567 0547654321"
     * still finds the first number and "050 123 4567" stays one number.
     */
    private static function fromCandidate(string $candidate): ?string
    {
        $whole = self::international($candidate);
        if ($whole !== null) {
            return $whole;
        }
        $parts = preg_split('/\s+/', $candidate) ?: [];
        for ($start = 0; $start < count($parts); $start++) {
            for ($length = min(4, count($parts) - $start); $length >= 1; $length--) {
                $digits = self::international(implode(' ', array_slice($parts, $start, $length)));
                if ($digits !== null) {
                    return $digits;
                }
            }
        }

        return null;
    }

    /** International digits without the +, or null when this is not a mobile number. */
    public static function international(string $raw): ?string
    {
        $plus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($plus && str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        // +972 050..., the trunk 0 written after the country code.
        if (preg_match('/^(972|970)0(5\d{8})$/', $digits, $m) === 1) {
            return $m[1].$m[2];
        }
        if (preg_match('/^(972|970)5\d{8}$/', $digits) === 1) {
            return $digits;
        }
        if (! $plus && preg_match('/^05\d{8}$/', $digits) === 1) {
            $country = in_array(substr($digits, 0, 3), self::PALESTINIAN_PREFIXES, true) ? '970' : '972';

            return $country.substr($digits, 1);
        }
        // Any other country, only when written as international.
        if ($plus && ! str_starts_with($digits, '972') && ! str_starts_with($digits, '970') && preg_match('/^[1-9]\d{9,14}$/', $digits) === 1) {
            return $digits;
        }

        return null;
    }

    /** 972501234567 reads as 050-123-4567; other countries as +digits. */
    private static function display(string $digits): string
    {
        if (preg_match('/^(?:972|970)(5\d)(\d{3})(\d{4})$/', $digits, $m) === 1) {
            return '0'.$m[1].'-'.$m[2].'-'.$m[3];
        }

        return '+'.$digits;
    }

    /** Arabic-Indic and Persian digits typed on an Arabic keyboard. */
    private static function westernDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
