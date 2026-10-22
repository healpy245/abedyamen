<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RestaurantLogoResolver
{
    /**
     * Logos already stored from an earlier lookup. Missing restaurants stay null.
     *
     * @param  list<string>  $slugs
     * @return array<string, ?string>
     */
    public function cached(array $slugs): array
    {
        $logos = [];
        foreach ($this->safeSlugs($slugs) as $slug) {
            $logos[$slug] = $this->read($slug);
        }

        return $logos;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, ?string>
     */
    public function resolve(array $slugs): array
    {
        $safe = $this->safeSlugs($slugs);
        $logos = [];
        $missing = [];
        foreach ($safe as $slug) {
            if (! Cache::has($this->key($slug))) {
                $missing[] = $slug;
                continue;
            }
            $logos[$slug] = $this->read($slug);
        }

        if ($missing === []) {
            return $logos;
        }

        $responses = Http::pool(function (Pool $pool) use ($missing): void {
            foreach ($missing as $slug) {
                $pool->as($slug)
                    ->timeout(5)
                    ->connectTimeout(2)
                    ->withHeaders(['User-Agent' => 'kaman-support'])
                    ->get('https://'.$slug.'.kaman.rest');
            }
        });

        foreach ($missing as $slug) {
            $url = $this->extract(is_array($responses) ? ($responses[$slug] ?? null) : null);
            Cache::put($this->key($slug), $url ?? '', $url !== null ? now()->addHours(18) : now()->addHour());
            $logos[$slug] = $url;
        }

        return $logos;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function safeSlugs(array $slugs): array
    {
        $safe = [];
        foreach ($slugs as $slug) {
            $slug = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) !== 1) {
                continue;
            }
            $safe[$slug] = $slug;
        }

        return array_values($safe);
    }

    private function read(string $slug): ?string
    {
        $value = Cache::get($this->key($slug));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function extract(mixed $response): ?string
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        if (preg_match('#https://admin\.kaman\.rest/storage/logos/[A-Za-z0-9._-]+#', $response->body(), $match) !== 1) {
            return null;
        }

        return $match[0];
    }

    private function key(string $slug): string
    {
        return 'support.restaurant-logo.'.$slug;
    }
}
