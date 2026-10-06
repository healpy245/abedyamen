<?php

declare(strict_types=1);

namespace App\Services\Haat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class HaatImageDownloader
{
    public function download(?string $relativeOrAbsoluteUrl): ?string
    {
        $url = $this->absoluteUrl($relativeOrAbsoluteUrl);
        if ($url === null) {
            return null;
        }

        try {
            $http = Http::timeout(30)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer' => 'https://wolt.com/',
            ]);
            if (! config('services.haat.ssl_verify', config('services.kaman.ssl_verify', false))) {
                $http = $http->withoutVerifying();
            }

            $response = $http->get($url);
            if (! $response->successful()) {
                Log::warning('Menu image download failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->body();
            if ($body === '' || @getimagesizefromstring($body) === false) {
                Log::warning('Menu image download was not an image', [
                    'url' => $url,
                    'type' => $response->header('Content-Type'),
                    'bytes' => strlen($body),
                ]);

                return null;
            }

            $dir = storage_path('app/haat-images');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $jpeg = $this->asJpeg($body);
            $bytes = $jpeg ?? $body;
            $path = $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().($jpeg !== null ? '.jpg' : '.img');
            if (file_put_contents($path, $bytes) === false) {
                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            Log::warning('HAAT image download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function absoluteUrl(?string $relativeOrAbsoluteUrl): ?string
    {
        $value = trim((string) $relativeOrAbsoluteUrl);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $base = rtrim((string) config('services.haat.cdn_base_url', ''), '/');
        if ($base === '') {
            return null;
        }

        return $base.'/'.ltrim($value, '/');
    }

    private function asJpeg(string $body): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return $body;
        }
        $image = @imagecreatefromstring($body);
        if ($image === false) {
            return null;
        }
        ob_start();
        $ok = imagejpeg($image, null, 85);
        imagedestroy($image);
        $jpeg = ob_get_clean();

        return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    private function extensionFromUrl(string $url, ?string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        $contentType = strtolower((string) $contentType);
        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };
    }
}
