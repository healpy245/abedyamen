<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one push through Firebase Cloud Messaging (HTTP v1).
 *
 * The access token is signed here from the service-account JSON, so no extra
 * package is needed. Without that file every send is a quiet no-op.
 */
class FcmSender
{
    public const RESULT_SENT = 'sent';

    public const RESULT_GONE = 'gone';

    public const RESULT_FAILED = 'failed';

    public const RESULT_OFF = 'off';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string, mixed>|null|false */
    private array|null|false $account = false;

    public function enabled(): bool
    {
        return $this->account() !== null;
    }

    /**
     * @param  array<string, string>  $data  Values must be strings for FCM.
     * @return self::RESULT_*
     */
    public function send(string $token, string $title, string $body, array $data = [], string $channel = 'support_tickets', ?string $tag = null): string
    {
        $account = $this->account();
        if ($account === null) {
            return self::RESULT_OFF;
        }

        $accessToken = $this->accessToken($account);
        if ($accessToken === null) {
            return self::RESULT_FAILED;
        }

        $android = array_filter([
            'channel_id' => $channel,
            'tag' => $tag,
            'default_sound' => true,
        ], static fn ($value) => $value !== null);

        $message = [
            'token' => $token,
            // A notification message is drawn by Android itself, so it shows
            // even when the app is closed or was swiped away.
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data),
            'android' => [
                'priority' => 'HIGH',
                'notification' => $android,
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(8)
                ->acceptJson()
                ->post('https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send', ['message' => $message]);
        } catch (\Throwable $error) {
            Log::warning('fcm.send_failed', ['error' => $error->getMessage()]);

            return self::RESULT_FAILED;
        }

        if ($response->successful()) {
            return self::RESULT_SENT;
        }

        $status = (string) data_get($response->json(), 'error.status', '');
        $code = (string) data_get($response->json(), 'error.details.0.errorCode', '');
        if ($response->status() === 404 || $status === 'NOT_FOUND' || $code === 'UNREGISTERED') {
            return self::RESULT_GONE;
        }
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($account));
        }
        Log::warning('fcm.send_rejected', ['status' => $response->status(), 'error' => $status, 'code' => $code]);

        return self::RESULT_FAILED;
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    private function account(): ?array
    {
        if ($this->account !== false) {
            return $this->account;
        }

        $path = (string) config('services.fcm.credentials');
        if ($path === '') {
            $path = storage_path('app/private/firebase-workspace.json');
        }
        $this->account = null;
        if (! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            Log::warning('fcm.bad_credentials', ['path' => basename($path)]);

            return null;
        }

        return $this->account = [
            'project_id' => (string) $json['project_id'],
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $account
     */
    private function accessToken(array $account): ?string
    {
        $cached = Cache::get($this->cacheKey($account));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];
        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('fcm.sign_failed');

            return null;
        }
        $segments[] = $this->base64Url($signature);

        try {
            $response = Http::asForm()->timeout(8)->post($account['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
        } catch (\Throwable $error) {
            Log::warning('fcm.token_failed', ['error' => $error->getMessage()]);

            return null;
        }

        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            Log::warning('fcm.token_rejected', ['status' => $response->status()]);

            return null;
        }

        $ttl = max(60, ((int) $response->json('expires_in', 3600)) - 300);
        Cache::put($this->cacheKey($account), $token, $ttl);

        return $token;
    }

    /**
     * @param  array{client_email: string}  $account
     */
    private function cacheKey(array $account): string
    {
        return 'fcm:access:'.sha1($account['client_email']);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
