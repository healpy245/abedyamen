<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Voice\Realtime\RealtimeCallsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DiagnoseRealtimeConnectCommand extends Command
{
    protected $signature = 'voice:realtime-diagnose-connect {--force : Run even if diagnostics are disabled}';

    protected $description = 'Send a local SDP fixture to OpenAI /realtime/calls via Guzzle and cURL (local only)';

    public function handle(RealtimeCallsClient $client): int
    {
        if (! $this->option('force') && ! config('voice.realtime.diagnostics.enabled')) {
            $this->error('Set VOICE_REALTIME_DIAGNOSE=true or pass --force');

            return self::FAILURE;
        }

        if (! app()->environment('local') && ! $this->option('force')) {
            $this->error('Diagnostics are limited to the local environment unless --force is provided');

            return self::FAILURE;
        }

        $apiKey = trim((string) (config('openai.api_key') ?: config('services.openai.api_key')));
        if ($apiKey === '') {
            $this->error('OPENAI_API_KEY is missing');

            return self::FAILURE;
        }

        $sdp = $this->fixtureOfferSdp();
        $session = [
            'type' => 'realtime',
            'model' => (string) config('voice.realtime.model', 'gpt-realtime'),
            'audio' => [
                'output' => [
                    'voice' => (string) config('voice.realtime.voice', 'marin'),
                ],
            ],
        ];

        $this->line('Fixture SDP strlen: '.strlen($sdp));
        $this->line('starts v=0: '.(str_starts_with($sdp, 'v=0') ? 'yes' : 'no'));
        $this->line('has m=audio: '.(str_contains($sdp, 'm=audio') ? 'yes' : 'no'));

        config([
            'voice.realtime.diagnostics.ab_curl' => true,
        ]);

        $baseUrl = rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/');
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);
        $organization = config('openai.organization');
        $organization = is_string($organization) ? $organization : null;

        $response = $client->exchangeSdp(
            $baseUrl,
            $apiKey,
            $sdp,
            $session,
            $sslVerify,
            $organization,
            (int) config('voice.realtime.timeout', 30),
        );

        $this->newLine();
        $this->info('Selected transport: '.$response->transport);
        $this->line('Status: '.$response->status);
        $this->line('Content-Type: '.$response->contentType);
        $this->line('Body starts with v=0: '.(str_starts_with($response->body, 'v=0') ? 'yes' : 'no'));
        $this->line('Body size: '.strlen($response->body));

        $error = json_decode($response->body, true);
        if (is_array($error) && isset($error['error']['message'])) {
            $this->warn('OpenAI error: '.$error['error']['message']);
        }

        $this->line('Laravel log: '.storage_path('logs/laravel.log'));

        Log::info('Realtime diagnose command completed', [
            'transport' => $response->transport,
            'status' => $response->status,
            'body_size' => strlen($response->body),
        ]);

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function fixtureOfferSdp(): string
    {
        return "v=0\r\n"
            ."o=- 123456789 2 IN IP4 127.0.0.1\r\n"
            ."s=-\r\n"
            ."t=0 0\r\n"
            ."a=group:BUNDLE 0\r\n"
            ."m=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"
            ."c=IN IP4 0.0.0.0\r\n"
            ."a=rtcp:9 IN IP4 0.0.0.0\r\n"
            ."a=ice-ufrag:abcd\r\n"
            ."a=ice-pwd:abcdefghijklmnopqrstuvwxyz123456\r\n"
            ."a=fingerprint:sha-256 00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF\r\n"
            ."a=setup:actpass\r\n"
            ."a=mid:0\r\n"
            ."a=sendrecv\r\n"
            ."a=rtcp-mux\r\n"
            ."a=rtpmap:111 opus/48000/2\r\n"
            ."a=fmtp:111 minptime=10;useinbandfec=1\r\n";
    }
}
