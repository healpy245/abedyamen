<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

use App\Support\Voice\RealtimeSdpTracer;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

class RealtimeCallsClient
{
    public function __construct(
        private ?ClientInterface $httpClient = null,
    ) {}

    /**
     * @param  array<string, mixed>  $sessionConfig
     */
    public function exchangeSdp(
        string $baseUrl,
        string $apiKey,
        string $sdp,
        array $sessionConfig,
        bool $sslVerify = true,
        ?string $organization = null,
        int $timeoutSeconds = 30,
    ): RealtimeCallsResponse {
        $sessionJson = json_encode($sessionConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $multipart = $this->buildMultipart($sdp, $sessionJson);
        $headers = $this->buildHeaders($apiKey, $organization);
        $url = rtrim($baseUrl, '/').'/realtime/calls';

        RealtimeSdpTracer::traceOutboundPayload($sdp, $sessionJson, $multipart);

        $transport = (string) config('voice.realtime.transport', 'guzzle');
        $runAbTest = (bool) config('voice.realtime.diagnostics.ab_curl', false);

        $guzzleResponse = $this->sendViaGuzzle($url, $headers, $multipart, $sslVerify, $timeoutSeconds);

        if ($runAbTest || $transport === 'curl') {
            $curlResponse = $this->sendViaCurl($url, $headers, $sdp, $sessionJson, $sslVerify, $timeoutSeconds);

            Log::info('Realtime transport A/B', [
                'guzzle_status' => $guzzleResponse->status,
                'guzzle_error' => self::extractErrorMessage($guzzleResponse->body),
                'curl_status' => $curlResponse->status,
                'curl_error' => self::extractErrorMessage($curlResponse->body),
            ]);

            if ($transport === 'curl' || (! $guzzleResponse->successful() && $curlResponse->successful())) {
                return $curlResponse;
            }
        }

        return $guzzleResponse;
    }

    /**
     * @return list<array{name: string, contents: string}>
     */
    public function buildMultipart(string $sdp, string $sessionJson): array
    {
        return [
            [
                'name' => 'sdp',
                'contents' => $sdp,
                'headers' => ['Content-Type' => 'application/sdp'],
            ],
            [
                'name' => 'session',
                'contents' => $sessionJson,
                'headers' => ['Content-Type' => 'application/json'],
            ],
        ];
    }

    /**
     * @param  list<array{name: string, contents: string}>  $multipart
     */
    public function sendViaGuzzle(
        string $url,
        array $headers,
        array $multipart,
        bool $sslVerify,
        int $timeoutSeconds,
    ): RealtimeCallsResponse {
        $client = $this->httpClient ?? $this->createGuzzleClient($sslVerify, $timeoutSeconds);

        $response = $client->post($url, [
            'headers' => $headers,
            'multipart' => $multipart,
            'http_errors' => false,
        ]);

        return new RealtimeCallsResponse(
            status: $response->getStatusCode(),
            body: (string) $response->getBody(),
            contentType: $response->getHeaderLine('Content-Type'),
            transport: 'guzzle',
        );
    }

    public function sendViaCurl(
        string $url,
        array $headers,
        string $sdp,
        string $sessionJson,
        bool $sslVerify,
        int $timeoutSeconds,
    ): RealtimeCallsResponse {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name.': '.$value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'sdp' => $sdp,
                'session' => $sessionJson,
            ],
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $body = json_encode(['error' => ['message' => $curlError ?: 'curl_failed']], JSON_THROW_ON_ERROR);
            $status = $status > 0 ? $status : 502;
        }

        Log::info('Realtime cURL transport result', [
            'status' => $status,
            'content_type' => $contentType,
            'body_size' => strlen((string) $body),
            'curl_error' => $curlError !== '' ? $curlError : null,
        ]);

        return new RealtimeCallsResponse(
            status: $status,
            body: (string) $body,
            contentType: $contentType,
            transport: 'curl',
        );
    }

    public static function registerRequestDiagnosticsMiddleware(HandlerStack $stack): void
    {
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            RealtimeSdpTracer::tracePsr7Request($request);

            return $request;
        }));
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $apiKey, ?string $organization): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/sdp',
        ];

        if ($organization !== null && $organization !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }

        return $headers;
    }

    private function createGuzzleClient(bool $sslVerify, int $timeoutSeconds): Client
    {
        $stack = HandlerStack::create();
        self::registerRequestDiagnosticsMiddleware($stack);

        return new Client([
            'handler' => $stack,
            'verify' => $sslVerify,
            'timeout' => $timeoutSeconds,
            'http_errors' => false,
        ]);
    }

    private static function extractErrorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        return is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['message'] ?? null)
            : null;
    }
}
