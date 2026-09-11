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
            $http = Http::timeout(20);
            if (! config('services.haat.ssl_verify', config('services.kaman.ssl_verify', false))) {
                $http = $http->withoutVerifying();
            }

            $response = $http->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '') {
                return null;
            }

            $extension = $this->extensionFromUrl($url, $response->header('Content-Type'));
            $dir = storage_path('app/haat-images');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $path = $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.'.$extension;
            if (file_put_contents($path, $body) === false) {
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
