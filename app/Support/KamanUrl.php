<?php

declare(strict_types=1);

namespace App\Support;

final class KamanUrl
{
    public static function tld(): string
    {
        $tld = (string) config('services.kaman.api_tld', 'dev');

        return in_array($tld, ['dev', 'rest'], true) ? $tld : 'dev';
    }

    public static function tldFromEnvironment(?string $environment): string
    {
        $environment = strtolower(trim((string) $environment));

        return in_array($environment, ['dev', 'rest'], true) ? $environment : 'rest';
    }

    public static function host(string $subdomain, ?string $tld = null): string
    {
        $subdomain = self::normalizeSubdomain($subdomain);
        $tld ??= self::tld();
        if (!in_array($tld, ['dev', 'rest'], true)) {
            $tld = self::tld();
        }

        return 'https://' . $subdomain . '.kaman.' . $tld;
    }

    /** Manager API root, e.g. https://thex.kaman.dev/api/manager */
    public static function managerApi(string $subdomain, ?string $tld = null): string
    {
        return self::host($subdomain, $tld) . '/api/manager';
    }

    public static function loginEmail(string $subdomain, ?string $username = null): string
    {
        $username = trim((string) $username);
        if ($username !== '') {
            return $username;
        }

        return self::normalizeSubdomain($subdomain) . '@kaman.rest';
    }

    /**
     * Build a full URL from a manager API base (or legacy host-only base) and a path.
     * Accepts paths like /login, /inventory-items, or /api/manager/items.
     */
    public static function join(string $baseUrl, string $path): string
    {
        $base = rtrim($baseUrl, '/');
        $path = '/' . ltrim($path, '/');

        if (str_ends_with($base, '/api/manager')) {
            if (str_starts_with($path, '/api/manager/')) {
                $path = substr($path, strlen('/api/manager')) ?: '/';
            } elseif ($path === '/api/manager') {
                $path = '';
            }

            return $base . $path;
        }

        if (str_starts_with($path, '/api/manager')) {
            return $base . $path;
        }

        return $base . '/api/manager' . $path;
    }

    public static function normalizeSubdomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\.kaman\.(rest|dev)$/i', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\-]/', '', $value) ?? $value;

        return $value;
    }
}
