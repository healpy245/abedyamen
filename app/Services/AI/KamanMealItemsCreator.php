<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\KamanUrl;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creates Kaman menu items with optional images (parallel requests + image compression).
 */
final class KamanMealItemsCreator
{
    /**
     * @param  array<string, array<string, mixed>>  $meals  Must include image_path key when uploading images.
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: list<array{key: string, id?: mixed}>, failed: list<array{key: string, error: string}>}
     */
    public static function create(
        string $baseUrl,
        string $token,
        array $meals,
        ?callable $progress = null,
        int $concurrency = 4,
        string $logContext = 'KamanMealItemsCreator',
    ): array {
        $keys = array_keys($meals);
        $total = count($keys);
        $created = [];
        $failed = [];
        $sslVerify = (bool) config('services.kaman.ssl_verify', false);
        $timeout = 120;

        for ($offset = 0; $offset < $total; $offset += $concurrency) {
            $batchKeys = array_slice($keys, $offset, $concurrency);
            $tempFiles = [];

            $responses = Http::pool(function (Pool $pool) use (
                $baseUrl,
                $token,
                $meals,
                $batchKeys,
                $sslVerify,
                $timeout,
                &$tempFiles,
            ) {
                $requests = [];

                foreach ($batchKeys as $key) {
                    $meal = $meals[$key];

                    $body = [
                        'name_ar' => $meal['name_ar'] ?? '',
                        'name_en' => $meal['name_en'] ?? '',
                        'name_he' => $meal['name_he'] ?? '',
                        'price' => $meal['price'] ?? '0.00',
                        'category_id' => $meal['category_id'] ?? '',
                        'description_ar' => $meal['description_ar'] ?? '',
                        'description_en' => $meal['description_en'] ?? '',
                        'description_he' => $meal['description_he'] ?? '',
                    ];

                    $request = $pool->as((string) $key)
                        ->timeout($timeout)
                        ->acceptJson()
                        ->withToken($token);

                    if (!$sslVerify) {
                        $request = $request->withoutVerifying();
                    }

                    $imagePath = $meal['image_path'] ?? null;
                    if (is_string($imagePath) && $imagePath !== '' && File::exists($imagePath)) {
                        $optimized = MealImageOptimizer::optimizeForUpload($imagePath);
                        if ($optimized['temporary']) {
                            $tempFiles[] = $optimized['path'];
                        }
                        $uploadPath = $optimized['path'];
                        $request = $request->attach(
                            'image',
                            File::get($uploadPath),
                            pathinfo($uploadPath, PATHINFO_BASENAME) ?: 'meal.jpg'
                        );
                    }

                    $requests[] = $request->post(KamanUrl::join($baseUrl, '/items'), $body);
                }

                return $requests;
            });

            foreach ($batchKeys as $i => $key) {
                $meal = $meals[$key];
                $itemNum = $offset + $i + 1;
                $progress && $progress(
                    'item',
                    'Creating item ' . $itemNum . '/' . $total . ': ' . ($meal['name_en'] ?? $meal['name_ar'] ?? $key),
                    ['key' => $key]
                );

                $response = $responses[(string) $key] ?? null;

                if ($response === null) {
                    $failed[] = ['key' => $key, 'error' => 'No response from API.'];
                    continue;
                }

                try {
                    if ($response->successful()) {
                        $data = $response->json();
                        $created[] = [
                            'key' => $key,
                            'id' => $data['data']['id'] ?? $data['id'] ?? $data['item']['id'] ?? null,
                        ];
                    } else {
                        $message = $response->json('message') ?? $response->json('error') ?? $response->body();
                        $error = is_string($message) ? $message : json_encode($message);
                        $failed[] = ['key' => $key, 'error' => $error];
                        Log::warning("{$logContext} item creation failed", [
                            'key' => $key,
                            'status' => $response->status(),
                            'response' => $error,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failed[] = ['key' => $key, 'error' => $e->getMessage()];
                    Log::warning("{$logContext} item request failed", ['key' => $key, 'error' => $e->getMessage()]);
                }
            }

            foreach ($tempFiles as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
