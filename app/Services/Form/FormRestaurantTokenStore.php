<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Models\FormRestaurantToken;
use App\Models\User;
use App\Support\KamanUrl;
use Illuminate\Contracts\Encryption\DecryptException;

final class FormRestaurantTokenStore
{
    public function remember(User $user, string $baseUrl, string $token, ?string $username = null): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        $parts = $this->parts($baseUrl);
        $values = [
            'subdomain' => $parts['subdomain'],
            'environment' => $parts['environment'],
            'token' => $token,
        ];
        $username = trim((string) $username);
        if ($username !== '') {
            $values['username'] = $username;
        }

        FormRestaurantToken::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'base_url' => $baseUrl,
            ],
            $values,
        );
    }

    /**
     * @return array{token: string, base_url: string}|null
     */
    public function recall(User $user, string $subdomain, string $environment): ?array
    {
        $baseUrl = $this->baseUrl($subdomain, $environment);
        $row = FormRestaurantToken::query()
            ->where('user_id', $user->id)
            ->where('base_url', $baseUrl)
            ->first();
        if ($row === null) {
            return null;
        }

        try {
            $token = $row->token;
        } catch (DecryptException) {
            return null;
        }
        if ($token === '') {
            return null;
        }

        return [
            'token' => $token,
            'base_url' => $baseUrl,
        ];
    }

    /**
     * @return list<array{subdomain: string, environment: string, host: string, username: string}>
     */
    public function listFor(User $user): array
    {
        return FormRestaurantToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FormRestaurantToken $row): array => [
                'subdomain' => $row->subdomain,
                'environment' => $row->environment,
                'host' => KamanUrl::host($row->subdomain, $row->environment),
                'username' => (string) $row->username,
            ])
            ->values()
            ->all();
    }

    public function forget(User $user, string $subdomain, string $environment): void
    {
        FormRestaurantToken::query()
            ->where('user_id', $user->id)
            ->where('base_url', $this->baseUrl($subdomain, $environment))
            ->delete();
    }

    private function baseUrl(string $subdomain, string $environment): string
    {
        return rtrim(KamanUrl::managerApi(
            KamanUrl::normalizeSubdomain($subdomain),
            KamanUrl::tldFromEnvironment($environment),
        ), '/');
    }

    /**
     * @return array{subdomain: string, environment: string}
     */
    private function parts(string $baseUrl): array
    {
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '');
        if (preg_match('/^([a-z0-9-]+)\.kaman\.(rest|dev)$/i', $host, $match) === 1) {
            return [
                'subdomain' => strtolower($match[1]),
                'environment' => strtolower($match[2]),
            ];
        }

        return [
            'subdomain' => 'restaurant',
            'environment' => 'rest',
        ];
    }
}
