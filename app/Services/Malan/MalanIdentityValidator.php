<?php

declare(strict_types=1);

namespace App\Services\Malan;

final class MalanIdentityValidator
{
    /**
     * @return array{valid:bool,normalized:?string,error:?string}
     */
    public function normalizeAndValidate(string $raw): array
    {
        $value = trim($raw);
        $value = str_replace([' ', '-', '_'], '', $value);

        if ($value === '') {
            return ['valid' => false, 'normalized' => null, 'error' => 'empty'];
        }

        if (! preg_match('/^\d+$/', $value)) {
            return ['valid' => false, 'normalized' => null, 'error' => 'non_numeric'];
        }

        // Israeli ID is up to 9 digits; pad for checksum validation.
        if (strlen($value) > 9) {
            return ['valid' => false, 'normalized' => null, 'error' => 'invalid_length'];
        }

        $padded = str_pad($value, 9, '0', STR_PAD_LEFT);

        if (! $this->passesIsraeliChecksum($padded)) {
            return ['valid' => false, 'normalized' => null, 'error' => 'checksum_failed'];
        }

        // Keep original significant digits (without unnecessary leading zeros beyond natural form).
        $normalized = ltrim($padded, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return ['valid' => true, 'normalized' => $normalized, 'error' => null];
    }

    private function passesIsraeliChecksum(string $nineDigits): bool
    {
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $nineDigits[$i];
            $step = $digit * (($i % 2) + 1);
            $sum += $step > 9 ? $step - 9 : $step;
        }

        return $sum % 10 === 0;
    }
}
