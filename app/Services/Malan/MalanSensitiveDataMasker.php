<?php

declare(strict_types=1);

namespace App\Services\Malan;

final class MalanSensitiveDataMasker
{
    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);

        if ($length < 4) {
            return str_repeat('*', $length);
        }

        if ($length <= 7) {
            return substr($digits, 0, 2).str_repeat('*', max(0, $length - 4)).substr($digits, -2);
        }

        return substr($digits, 0, 3).str_repeat('*', $length - 7).substr($digits, -4);
    }

    public static function maskIdentity(?string $identity): ?string
    {
        if ($identity === null) {
            return null;
        }

        if (str_contains($identity, '*')) {
            return $identity;
        }

        $digits = preg_replace('/\D+/', '', $identity) ?? '';
        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($digits, -4);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizeToolArguments(array $payload): array
    {
        $copy = $payload;

        if (isset($copy['value']) && is_string($copy['value'])) {
            $type = (string) ($copy['lookup_type'] ?? '');
            $copy['value'] = $type === 'identity'
                ? self::maskIdentity($copy['value'])
                : self::maskPhone($copy['value']);
        }

        foreach (['identity', 'client_identity', 'national_id'] as $key) {
            if (isset($copy[$key]) && is_string($copy[$key])) {
                $copy[$key] = self::maskIdentity($copy[$key]);
            }
        }

        foreach (['phone', 'client_phone'] as $key) {
            if (isset($copy[$key]) && is_string($copy[$key])) {
                $copy[$key] = self::maskPhone($copy[$key]);
            }
        }

        return $copy;
    }
}
