<?php

declare(strict_types=1);

namespace App\Services\Malan;

final class MalanPhoneNormalizer
{
    /**
     * @return array{valid:bool,normalized:?string,error:?string}
     */
    public function normalize(string $raw): array
    {
        $value = trim($raw);
        $value = str_replace([' ', '-', '(', ')', '.'], '', $value);

        if ($value === '') {
            return ['valid' => false, 'normalized' => null, 'error' => 'empty'];
        }

        if (str_starts_with($value, '00972')) {
            $value = '+972'.substr($value, 5);
        }

        if (str_starts_with($value, '+972')) {
            $rest = substr($value, 4);
            $rest = ltrim($rest, '0');
            $value = '0'.$rest;
        } elseif (str_starts_with($value, '972') && strlen($value) >= 11) {
            $rest = substr($value, 3);
            $rest = ltrim($rest, '0');
            $value = '0'.$rest;
        }

        if (! preg_match('/^\d+$/', $value)) {
            return ['valid' => false, 'normalized' => null, 'error' => 'non_numeric'];
        }

        // Israeli mobile: 05XXXXXXXX (10 digits)
        if (! preg_match('/^05\d{8}$/', $value)) {
            return ['valid' => false, 'normalized' => null, 'error' => 'invalid_format'];
        }

        return ['valid' => true, 'normalized' => $value, 'error' => null];
    }
}
