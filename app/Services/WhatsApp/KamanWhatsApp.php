<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\KamanWhatsappLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends from the Kaman WhatsApp line. Green API is the default; Whapi is used when that switch is on.
 */
class KamanWhatsApp
{
    public function line(): ?KamanWhatsappLine
    {
        return KamanWhatsappLine::kaman();
    }

    public function sendUrl(): string
    {
        return trim((string) ($this->line()?->greenapi_url ?? ''));
    }

    public function usesWhapi(): bool
    {
        return $this->provider() === 'whapi';
    }

    public function provider(): string
    {
        $forced = strtolower(trim((string) config('whapi.provider', '')));
        if (in_array($forced, ['whapi', 'greenapi'], true)) {
            return $forced;
        }

        if (app()->runningUnitTests()) {
            return $this->storedProvider();
        }

        return Cache::remember('whatsapp_provider', 15, fn (): string => $this->storedProvider());
    }

    public function normalizePhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && ! str_starts_with($digits, '00')) {
            $digits = '972'.substr($digits, 1);
        }

        return $digits;
    }

    public function chatIdFromPhone(string $phone): string
    {
        $digits = $this->normalizePhoneDigits($phone);

        return $digits === '' ? '' : $digits.'@c.us';
    }

    public function uploadUrlFromSendUrl(string $sendUrl): ?string
    {
        $sendUrl = trim($sendUrl);
        if ($sendUrl === '' || ! str_contains($sendUrl, '/sendMessage/')) {
            return null;
        }

        return str_ireplace('/sendMessage/', '/sendFileByUpload/', $sendUrl);
    }

    /**
     * @return array{status: int, body: mixed}
     */
    public function sendMessage(string $sendUrl, string $chatId, string $message): array
    {
        if ($this->usesWhapi()) {
            return $this->postWhapi('/messages/text', [
                'to' => $this->recipient($chatId),
                'body' => $message,
            ], $chatId, 30);
        }

        try {
            $response = Http::connectTimeout(8)
                ->timeout(25)
                ->acceptJson()
                ->post($sendUrl, [
                    'chatId' => $chatId,
                    'message' => $message,
                ]);
        } catch (Throwable $e) {
            Log::warning('Green API sendMessage failed', [
                'status' => 0,
                'body' => $e->getMessage(),
                'chat_id' => $chatId,
            ]);

            return ['status' => 0, 'body' => 'request_failed'];
        }

        if (! $response->successful()) {
            Log::warning('Green API sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * @return array{status: int, body: mixed}
     */
    public function sendPdf(string $chatId, string $bytes, string $fileName, string $caption): array
    {
        $caption = trim($caption);

        if ($this->usesWhapi()) {
            $payload = [
                'to' => $this->recipient($chatId),
                'media' => 'data:application/pdf;base64,'.base64_encode($bytes),
                'filename' => $fileName,
            ];
            if ($caption !== '') {
                $payload['caption'] = $caption;
            }

            return $this->postWhapi('/messages/document', $payload, $chatId, 60);
        }

        $uploadUrl = $this->uploadUrlFromSendUrl($this->sendUrl());
        if ($uploadUrl === null) {
            return ['status' => 0, 'body' => null];
        }

        $fields = [
            'chatId' => $chatId,
            'fileName' => $fileName,
        ];
        if ($caption !== '') {
            $fields['caption'] = $caption;
        }

        $response = Http::timeout(90)
            ->attach('file', $bytes, $fileName, ['Content-Type' => 'application/pdf'])
            ->post($uploadUrl, $fields);

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    private function storedProvider(): string
    {
        if (! $this->settingsTableExists()) {
            return 'greenapi';
        }

        $stored = strtolower(trim((string) DB::table('ai_chatbot_settings')->where('key', 'whatsapp_provider')->value('value')));

        return $stored === 'whapi' ? 'whapi' : 'greenapi';
    }

    private function token(): string
    {
        if (! app()->runningUnitTests() && Cache::has('whapi_token')) {
            return trim((string) Cache::get('whapi_token'));
        }

        $stored = '';
        if ($this->settingsTableExists()) {
            $stored = trim((string) DB::table('ai_chatbot_settings')->where('key', 'whapi_token')->value('value'));
        }
        if ($stored === '') {
            $stored = trim((string) config('whapi.token', ''));
        }

        if (! app()->runningUnitTests()) {
            Cache::put('whapi_token', $stored, 30);
        }

        return $stored;
    }

    private function settingsTableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('ai_chatbot_settings');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('whapi.base_url', 'https://gate.whapi.cloud'), '/');
    }

    private function recipient(string $chatId): string
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return $chatId;
        }

        $lower = strtolower($chatId);
        if (! str_ends_with($lower, '@c.us') && ! str_ends_with($lower, '@s.whatsapp.net')) {
            if (str_contains($chatId, '@')) {
                return $chatId;
            }

            $digits = preg_replace('/\D+/', '', $chatId) ?? '';

            return $digits !== '' ? $digits : $chatId;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';

        return $digits !== '' ? $digits : $chatId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: mixed}
     */
    private function postWhapi(string $path, array $payload, string $chatId, int $timeout): array
    {
        $token = $this->token();
        if ($token === '') {
            return ['status' => 0, 'body' => 'missing_whapi_token'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->post($this->baseUrl().$path, $payload);
        } catch (Throwable $e) {
            Log::warning('Whapi send failed', [
                'path' => $path,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 0, 'body' => 'request_failed'];
        }

        if (! $response->successful()) {
            Log::warning('Whapi send failed', [
                'path' => $path,
                'status' => $response->status(),
                'chat_id' => $chatId,
            ]);
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }
}
