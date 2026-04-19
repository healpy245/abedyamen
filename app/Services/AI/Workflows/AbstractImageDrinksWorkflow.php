<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractImageDrinksWorkflow extends AbstractFormWorkflow
{
    abstract protected function getItemLabel(): string;

    abstract protected function getDefaultCategory(): string;

    abstract protected function getTranslations(): array;

    abstract protected function getBrands(): array;

    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');
        $drinksSelection = $payload['drinks_selection'] ?? [];
        $imageDirectory = $payload['drinks_directory'] ?? 'ColdDrinks';

        $progress = static function (string $step, string $message, array $data = []) use ($onProgress): void {
            $onProgress && $onProgress($step, $message, $data);
        };

        if ($restaurantName === '' || $password === '') {
            return [
                'success' => false,
                'error' => 'Restaurant name and password are required.',
            ];
        }

        if ($description === '' || empty($drinksSelection)) {
            return [
                'success' => false,
                'error' => 'Please select at least one ' . $this->getItemLabel() . ' and provide its price.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = "https://{$subdomain}.kaman.rest";
        $imageBasePath = public_path($imageDirectory);

        set_time_limit(600);

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $token = $this->login($baseUrl, $subdomain, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching categories...', []);
            $categories = $this->fetchCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' categories', ['count' => count($categories)]);

            $progress('ai', 'Parsing ' . $this->getItemLabel() . 's with AI (with images)...', []);
            $drinks = $this->parseDrinksWithAi($description, $drinksSelection, $imageBasePath, $categories);
            $progress('ai', 'Parsed ' . count($drinks) . ' ' . $this->getItemLabel() . 's', ['count' => count($drinks)]);

            $progress('items', 'Creating ' . $this->getItemLabel() . 's via Kaman API...', []);
            $itemsResult = $this->createDrinkItems($baseUrl, $token, $drinks, $progress);
            $progress('items', 'Created ' . count($itemsResult['created']) . ' items, ' . count($itemsResult['failed']) . ' failed', $itemsResult);

            Log::info(static::class . ' completed', [
                'restaurant' => $restaurantName,
                'items_count' => count($drinks),
                'items_created' => $itemsResult['created'],
                'items_failed' => $itemsResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => ucfirst($this->getItemLabel()) . ' store processed successfully',
                'data' => [
                    'token' => $token,
                    'items' => $drinks,
                    'items_created' => $itemsResult['created'],
                    'items_failed' => $itemsResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error(static::class . ' failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);
            throw $e;
        }
    }

    protected function http(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout($timeout)->acceptJson();
        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }
        return $http;
    }

    protected function login(string $baseUrl, string $subdomain, string $password): string
    {
        $response = $this->http()->post("{$baseUrl}/api/manager/login", [
            'email' => "{$subdomain}@kaman.rest",
            'password' => $password,
        ]);
        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();
            throw new \RuntimeException('Login failed: ' . (is_string($message) ? $message : json_encode($message)));
        }
        $data = $response->json();
        $token = $data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? null;
        if ($token === null) {
            throw new \RuntimeException('Login response did not contain a token.');
        }
        return $token;
    }

    protected function fetchCategories(string $baseUrl, string $token): array
    {
        $response = $this->http()->withToken($token)->get("{$baseUrl}/api/manager/categories");
        if (!$response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();
            throw new \RuntimeException('Failed to fetch categories: ' . (is_string($message) ? $message : json_encode($message)));
        }
        $data = $response->json();
        $list = $data['data'] ?? $data['categories'] ?? $data;
        if (!is_array($list)) {
            throw new \RuntimeException('Categories response format is invalid.');
        }
        return $list;
    }

    protected function findImagePath(string $basePath, string $key): ?string
    {
        if (!File::isDirectory($basePath)) {
            return null;
        }
        $keyLower = strtolower(trim($key));
        $keyNorm = str_replace(['-', ' ', '_'], '', $keyLower);
        foreach (File::files($basePath) as $file) {
            $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $nameLower = strtolower($name);
            $nameNorm = str_replace(['-', ' ', '_'], '', $nameLower);
            if ($nameLower === $keyLower || $nameNorm === $keyNorm) {
                return $file->getPathname();
            }
        }
        return null;
    }

    protected function parseDrinksWithAi(string $description, array $drinksSelection, string $imageBasePath, array $categories): array
    {
        $categoryName = $this->extractCategoryFromDescription($description);
        $drinks = [];
        $idx = 0;
        foreach ($drinksSelection as $item) {
            $key = $item['key'] ?? $item['name'] ?? ($this->getItemLabel() . (++$idx));
            $nameEn = trim((string) ($item['name'] ?? $item['label'] ?? $key));
            $price = isset($item['price']) ? number_format((float) $item['price'], 2, '.', '') : '0.00';
            $imagePath = $this->findImagePath($imageBasePath, $key);

            if ($imagePath) {
                $prompt = "Extract the drink/product name from this image. Return a JSON object with: name_ar (proper Arabic translation), name_en (English), name_he (proper Hebrew translation). Use native Arabic and Hebrew script - do not use transliteration. Add brief 1-line descriptions in description_ar, description_en, description_he. Output ONLY valid JSON, no markdown.";
                try {
                    $aiResponse = $this->analyzeImage($imagePath, $prompt, ['max_tokens' => 512]);
                    $parsed = $this->parseSingleDrinkAiResponse($aiResponse, $nameEn);
                } catch (\Throwable $e) {
                    $parsed = $this->translateNameToArabicHebrew($nameEn);
                }
            } else {
                $parsed = $this->translateNameToArabicHebrew($nameEn);
            }

            $nameEnFormatted = $this->formatProfessionalName($parsed['name_en'] ?? $nameEn);
            $nameAr = trim($parsed['name_ar'] ?? '');
            $nameHe = trim($parsed['name_he'] ?? '');

            if ($nameAr === '' || $nameHe === '' || $this->looksLikeEnglish($nameAr) || $this->looksLikeEnglish($nameHe)) {
                $translated = $this->translateNameToArabicHebrew($nameEnFormatted);
                $nameAr = $translated['name_ar'] ?: $nameEnFormatted;
                $nameHe = $translated['name_he'] ?: $nameEnFormatted;
            }

            $categoryId = $this->matchCategoryId($categories, $categoryName);
            $drinks[$key] = [
                'name_ar' => $nameAr,
                'name_en' => $nameEnFormatted,
                'name_he' => $nameHe,
                'price' => $price,
                'category_id' => $categoryId,
                'description_ar' => $parsed['description_ar'] ?? '',
                'description_en' => $parsed['description_en'] ?? '',
                'description_he' => $parsed['description_he'] ?? '',
                'image_path' => $imagePath,
            ];
        }
        return $drinks;
    }

    protected function translateNameToArabicHebrew(string $nameEn): array
    {
        $nameEn = trim($nameEn);
        if ($nameEn === '') {
            return ['name_ar' => '', 'name_en' => '', 'name_he' => '', 'description_ar' => '', 'description_en' => '', 'description_he' => ''];
        }
        $translations = $this->getTranslations();
        $key = strtolower($nameEn);
        if (isset($translations[$key])) {
            $t = $translations[$key];
            return ['name_ar' => $t['ar'], 'name_en' => $nameEn, 'name_he' => $t['he'], 'description_ar' => '', 'description_en' => '', 'description_he' => ''];
        }
        $sorted = $translations;
        uksort($sorted, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($sorted as $pattern => $t) {
            if (str_starts_with($key, $pattern)) {
                $suffix = trim(substr($nameEn, strlen($pattern)));
                if ($suffix === '') {
                    return ['name_ar' => $t['ar'], 'name_en' => $nameEn, 'name_he' => $t['he'], 'description_ar' => '', 'description_en' => '', 'description_he' => ''];
                }
                $suffixTrans = $this->translateNameToArabicHebrew($suffix);
                return [
                    'name_ar' => $t['ar'] . ' ' . $suffixTrans['name_ar'],
                    'name_en' => $nameEn,
                    'name_he' => $t['he'] . ' ' . $suffixTrans['name_he'],
                    'description_ar' => '',
                    'description_en' => '',
                    'description_he' => '',
                ];
            }
        }
        $prompt = "Translate this drink/product name to Arabic and Hebrew. Return JSON: {\"name_ar\":\"...\",\"name_en\":\"...\",\"name_he\":\"...\"}. Use native Arabic and Hebrew script. name_en stays as given. Output ONLY valid JSON.";
        try {
            $response = $this->chat($prompt, $nameEn, ['max_tokens' => 256]);
            $decoded = json_decode(trim(preg_replace('/```(?:json)?\s*|```/', '', $response)), true);
            if (is_array($decoded)) {
                return [
                    'name_ar' => (string) ($decoded['name_ar'] ?? $nameEn),
                    'name_en' => (string) ($decoded['name_en'] ?? $nameEn),
                    'name_he' => (string) ($decoded['name_he'] ?? $nameEn),
                    'description_ar' => '',
                    'description_en' => '',
                    'description_he' => '',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning(static::class . ' translateNameToArabicHebrew failed', ['name' => $nameEn, 'error' => $e->getMessage()]);
        }
        return ['name_ar' => $nameEn, 'name_en' => $nameEn, 'name_he' => $nameEn, 'description_ar' => '', 'description_en' => '', 'description_he' => ''];
    }

    protected function looksLikeEnglish(string $s): bool
    {
        return preg_match('/^[a-zA-Z0-9\s\-]+$/', $s) === 1;
    }

    protected function parseSingleDrinkAiResponse(string $response, string $fallbackName): array
    {
        $response = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['name_ar' => $fallbackName, 'name_en' => $fallbackName, 'name_he' => $fallbackName, 'description_ar' => '', 'description_en' => '', 'description_he' => ''];
        }
        return [
            'name_ar' => (string) ($decoded['name_ar'] ?? $decoded['nameAr'] ?? $fallbackName),
            'name_en' => (string) ($decoded['name_en'] ?? $decoded['nameEn'] ?? $fallbackName),
            'name_he' => (string) ($decoded['name_he'] ?? $decoded['nameHe'] ?? $fallbackName),
            'description_ar' => (string) ($decoded['description_ar'] ?? $decoded['descriptionAr'] ?? ''),
            'description_en' => (string) ($decoded['description_en'] ?? $decoded['descriptionEn'] ?? ''),
            'description_he' => (string) ($decoded['description_he'] ?? $decoded['descriptionHe'] ?? ''),
        ];
    }

    protected function formatProfessionalName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return '';
        }
        $nameNorm = str_replace(['-', '_', ' '], '', strtolower($name));
        $brands = $this->getBrands();
        foreach ($brands as $pattern => $proper) {
            if ($nameNorm === $pattern) {
                return $proper;
            }
            if (str_starts_with($nameNorm, $pattern)) {
                $rest = substr($nameNorm, strlen($pattern));
                $suffix = $this->formatSuffixWords($rest);
                return $suffix !== '' ? $proper . ' ' . $suffix : $proper;
            }
        }
        $suffixPattern = '/(zero|big|glass|can|bottle|regular|large|small|ice|iced|cola|lemonade|sprite|xl|ten|tea|coffee|espresso|mocha|latte)/i';
        $withSpaces = preg_replace($suffixPattern, ' $1', $nameNorm);
        $words = preg_split('/[\s\-_]+/', trim($withSpaces), -1, PREG_SPLIT_NO_EMPTY);
        $formatted = array_map(function (string $word): string {
            if (preg_match('/^\d+[a-z]?$/i', $word)) {
                return $word;
            }
            return ucfirst(strtolower($word));
        }, $words);
        return implode(' ', $formatted);
    }

    protected function formatSuffixWords(string $concat): string
    {
        $suffixes = ['glass' => 'Glass', 'can' => 'Can', 'bottle' => 'Bottle', 'big' => 'Big', 'zero' => 'Zero', 'regular' => 'Regular', 'large' => 'Large', 'small' => 'Small'];
        $result = [];
        $remaining = strtolower($concat);
        while ($remaining !== '') {
            $matched = false;
            foreach ($suffixes as $key => $formatted) {
                if (str_starts_with($remaining, $key)) {
                    $result[] = $formatted;
                    $remaining = substr($remaining, strlen($key));
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $result[] = ucfirst($remaining);
                break;
            }
        }
        return implode(' ', $result);
    }

    protected function extractCategoryFromDescription(string $description): string
    {
        if (preg_match('/^([^:]+)\s*:\s*\{\{/', trim($description), $m)) {
            return trim($m[1]);
        }
        return $this->getDefaultCategory();
    }

    protected function matchCategoryId(array $categories, string $categoryName): string
    {
        $catLower = strtolower($categoryName);
        foreach ($categories as $cat) {
            $name = strtolower((string) ($cat['name'] ?? $cat['name_en'] ?? $cat['name_ar'] ?? ''));
            if ($name === $catLower || str_contains($name, $catLower) || str_contains($catLower, $name)) {
                return (string) ($cat['id'] ?? $cat['category_id'] ?? '');
            }
        }
        $first = $categories[0] ?? null;
        return $first ? (string) ($first['id'] ?? $first['category_id'] ?? '') : '';
    }

    protected function createDrinkItems(string $baseUrl, string $token, array $drinks, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($drinks);
        $i = 0;
        $uploadTimeout = 90;
        foreach ($drinks as $key => $drink) {
            $i++;
            $progress && $progress('item', 'Creating ' . $this->getItemLabel() . ' ' . $i . '/' . $total . ': ' . ($drink['name_en'] ?? $key), ['key' => $key]);
            try {
                $body = [
                    'name_ar' => $drink['name_ar'],
                    'name_en' => $drink['name_en'],
                    'name_he' => $drink['name_he'],
                    'price' => $drink['price'],
                    'category_id' => $drink['category_id'],
                    'description_ar' => $drink['description_ar'],
                    'description_en' => $drink['description_en'],
                    'description_he' => $drink['description_he'],
                ];
                $http = $this->http($uploadTimeout)->withToken($token);
                if (!empty($drink['image_path']) && File::exists($drink['image_path'])) {
                    $http = $http->attach('image', File::get($drink['image_path']), File::basename($drink['image_path']));
                }
                $response = $http->post("{$baseUrl}/api/manager/items", $body);
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'error' => $e->getMessage()];
                Log::warning(static::class . ' item request failed', ['key' => $key, 'error' => $e->getMessage()]);
                continue;
            }
            if ($response->successful()) {
                $data = $response->json();
                $created[] = ['key' => $key, 'id' => $data['data']['id'] ?? $data['id'] ?? $data['item']['id'] ?? null];
            } else {
                $message = $response->json('message') ?? $response->json('error') ?? $response->body();
                $failed[] = ['key' => $key, 'error' => is_string($message) ? $message : json_encode($message)];
                Log::warning(static::class . ' item creation failed', ['key' => $key, 'status' => $response->status(), 'response' => $message]);
            }
        }
        return ['created' => $created, 'failed' => $failed];
    }

    protected function toSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        $subdomain = trim($subdomain, '-');
        $subdomain = preg_replace('/-+/', '-', $subdomain);
        return $subdomain ?: 'default';
    }
}
