<?php

declare(strict_types=1);

namespace App\Support\Voice;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

final class RealtimeSdpTracer
{
    public static function trace(string $point, string $sdp, array $extra = []): void
    {
        $preview = substr($sdp, 0, 40);

        Log::info('Realtime SDP trace', array_merge([
            'point' => $point,
            'php_type' => gettype($sdp),
            'strlen' => strlen($sdp),
            'starts_with_v0' => str_starts_with($sdp, 'v=0'),
            'has_m_audio' => str_contains($sdp, 'm=audio'),
            'preview_b64' => base64_encode($preview),
        ], $extra));
    }

    /**
     * @param  list<array{name: string, contents: string}>  $multipart
     */
    public static function traceOutboundPayload(
        string $sdp,
        string $sessionJson,
        array $multipart,
        string $point = 'C_client_before_guzzle',
    ): void {
        Log::info('Realtime SDP trace', [
            'point' => $point,
            'sdp_strlen' => strlen($sdp),
            'starts_with_v0' => str_starts_with($sdp, 'v=0'),
            'has_m_audio' => str_contains($sdp, 'm=audio'),
            'session_json_strlen' => strlen($sessionJson),
            'multipart_part_names' => array_column($multipart, 'name'),
        ]);
    }

    public static function tracePsr7Request(RequestInterface $request, string $point = 'D_psr7_before_transport'): void
    {
        $body = $request->getBody();
        $headers = [];

        foreach (['Authorization', 'Accept', 'Content-Type', 'Content-Length', 'OpenAI-Organization'] as $name) {
            $value = $request->getHeaderLine($name);
            if ($value !== '') {
                $headers[$name] = $name === 'Authorization'
                    ? self::redactAuthorization($value)
                    : $value;
            }
        }

        $contentType = $request->getHeaderLine('Content-Type');

        Log::info('Realtime SDP trace', [
            'point' => $point,
            'headers' => $headers,
            'content_type' => $contentType,
            'content_length_header' => $request->getHeaderLine('Content-Length'),
            'body_stream_size' => $body->getSize(),
            'body_is_seekable' => $body->isSeekable(),
            'body_is_readable' => $body->isReadable(),
            'manual_multipart_without_boundary' => str_contains($contentType, 'multipart/form-data')
                && ! str_contains($contentType, 'boundary='),
        ]);
    }

    public static function redactAuthorization(string $value): string
    {
        return (string) preg_replace('/^Bearer\s+\S+/i', 'Bearer [redacted]', $value);
    }
}
