<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GreenApiMediaDownloader
{
    /**
     * Download media to a private disk path. Returns relative storage path.
     *
     * @throws RuntimeException
     */
    public function downloadToPrivateStorage(string $url, ?string $declaredMime = null): array
    {
        $this->assertUrlAllowed($url);

        $timeout = (int) config('malan.media.download_timeout', 20);
        $maxBytes = (int) config('malan.media.max_bytes', 5 * 1024 * 1024);
        $allowedMimes = config('malan.media.allowed_mimes', []);
        $disk = (string) config('malan.media.disk', 'local');
        $directory = trim((string) config('malan.media.directory', 'malan/payment-proofs'), '/');

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('Green API media download failed', ['error' => $e->getMessage()]);

            throw new RuntimeException('Failed to download media.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Media download returned HTTP '.$response->status());
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Media exceeds maximum allowed size.');
        }

        $mime = $this->detectMime($body, $declaredMime, $response->header('Content-Type'));
        $baseMime = $this->baseMime($mime);
        $allowed = is_array($allowedMimes) ? $allowedMimes : [];
        if (! in_array($baseMime, $allowed, true) && ! in_array($mime, $allowed, true)) {
            // Some CDNs report voice notes as application/ogg.
            if ($baseMime === 'application/ogg' && in_array('audio/ogg', $allowed, true)) {
                $mime = 'audio/ogg';
                $baseMime = 'audio/ogg';
            } else {
                throw new RuntimeException('Media MIME type is not allowed.');
            }
        }
        $mime = $baseMime;

        $extension = match (true) {
            $mime === 'image/jpeg' => 'jpg',
            $mime === 'image/png' => 'png',
            $mime === 'image/webp' => 'webp',
            $mime === 'application/pdf' => 'pdf',
            $mime === 'audio/ogg', str_starts_with($mime, 'audio/ogg') => 'ogg',
            $mime === 'audio/mpeg' => 'mp3',
            $mime === 'audio/mp4', $mime === 'audio/aac', $mime === 'audio/x-m4a' => 'm4a',
            $mime === 'audio/opus' => 'opus',
            $mime === 'audio/wav', $mime === 'audio/x-wav' => 'wav',
            $mime === 'audio/webm' => 'webm',
            $mime === 'audio/3gpp' => '3gp',
            $mime === 'audio/amr' => 'amr',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'bin',
        };

        $relativePath = $directory.'/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk($disk)->put($relativePath, $body);

        return [
            'disk' => $disk,
            'path' => $relativePath,
            'mime_type' => $mime,
            'size' => strlen($body),
        ];
    }

    private function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid media URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('Unsupported media URL scheme.');
        }

        $host = strtolower((string) $parts['host']);
        $allowedHosts = config('malan.media.allowed_download_hosts', []);

        // Always allow green-api / WhatsApp CDN hosts; also allow exact configured list.
        // GreenAPI commonly stores media on DigitalOcean Spaces / S3-compatible CDNs.
        $trustedSuffixes = [
            'green-api.com',
            'greenapi.com',
            'whatsapp.net',
            'whatsapp.com',
            'fbcdn.net',
            'cdn.whatsapp.net',
            'digitaloceanspaces.com',
            'amazonaws.com',
            'cloudfront.net',
        ];
        $hostTrusted = false;
        foreach ($trustedSuffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $hostTrusted = true;
                break;
            }
        }

        if (! $hostTrusted && is_array($allowedHosts) && $allowedHosts !== []) {
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(trim((string) $allowed));
                if ($allowed === '') {
                    continue;
                }
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    $hostTrusted = true;
                    break;
                }
            }
        }

        if (! $hostTrusted) {
            Log::warning('Green API media host rejected', [
                'host' => $host,
                'url' => $url,
            ]);

            throw new RuntimeException('Media host is not allowed.');
        }

        // Block obvious SSRF targets.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private media IP is not allowed.');
            }
        }
    }

    private function detectMime(string $body, ?string $declaredMime, ?string $contentTypeHeader): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($body) ?: null;

        if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
            return strtolower($detected);
        }

        if (is_string($declaredMime) && $declaredMime !== '') {
            return strtolower(trim(explode(';', $declaredMime)[0]));
        }

        if (is_string($contentTypeHeader) && $contentTypeHeader !== '') {
            return strtolower(trim(explode(';', $contentTypeHeader)[0]));
        }

        return 'application/octet-stream';
    }

    /**
     * Normalize MIME that may include codecs (e.g. audio/ogg; codecs=opus) for allowlist checks.
     */
    private function baseMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }
}
