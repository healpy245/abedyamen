<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CategoryStoreWithAiImageWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');
        $logoPath = isset($payload['category_logo_path']) && is_string($payload['category_logo_path'])
            ? $payload['category_logo_path']
            : null;

        $progress = static function (string $step, string $message, array $data = []) use ($onProgress): void {
            $onProgress && $onProgress($step, $message, $data);
        };

        if ($restaurantName === '' || $password === '') {
            return [
                'success' => false,
                'error' => 'Restaurant name and password are required.',
            ];
        }

        if ($description === '') {
            return [
                'success' => false,
                'error' => 'Description with categories is required.',
            ];
        }

        if ($logoPath === null || !file_exists($logoPath)) {
            return [
                'success' => false,
                'error' => 'A valid logo image is required.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = "https://{$subdomain}.kaman.rest";

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $token = $this->login($baseUrl, $subdomain, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('ai', 'Parsing categories with AI...', []);
            $categories = $this->parseCategoriesWithAi($description);
            $progress('ai', 'Parsed ' . count($categories) . ' categories', ['count' => count($categories)]);

            $progress('style', 'Analyzing logo style for category images...', []);
            $styleDescription = $this->describeLogoStyle($logoPath);

            $progress('images', 'Generating images for categories...', []);
            $categoriesWithImages = $this->generateImagesForCategories($categories, $restaurantName, $styleDescription);

            $withImageCount = count(array_filter($categoriesWithImages, fn ($c) => !empty($c['image_path'] ?? null)));
            $progress('images', 'Generated images for ' . $withImageCount . ' categories', ['with_images' => $withImageCount]);

            $progress('categories', 'Creating categories via Kaman API...', []);
            $createResult = $this->createCategories($baseUrl, $token, $categoriesWithImages, $progress);
            $progress('categories', 'Created ' . count($createResult['created']) . ' categories, ' . count($createResult['failed']) . ' failed', $createResult);

            Log::info('CategoryStoreWithAiImageWorkflow completed', [
                'restaurant' => $restaurantName,
                'categories_count' => count($categoriesWithImages),
                'categories_created' => $createResult['created'],
                'categories_failed' => $createResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Category store (with AI images) processed successfully',
                'data' => [
                    'token' => $token,
                    'categories' => $categoriesWithImages,
                    'categories_created' => $createResult['created'],
                    'categories_failed' => $createResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('CategoryStoreWithAiImageWorkflow failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);

            throw $e;
        }
    }

    private function http(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout($timeout)->acceptJson();

        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function login(string $baseUrl, string $subdomain, string $password): string
    {
        $response = $this->http()->post("{$baseUrl}/api/manager/login", [
            'email' => "{$subdomain}@kaman.rest",
            'password' => $password,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            Log::warning('CategoryStoreWithAiImageWorkflow login failed', [
                'status' => $response->status(),
                'response' => $message,
            ]);

            throw new \RuntimeException('Login failed: ' . (is_string($message) ? $message : json_encode($message)));
        }

        $data = $response->json();
        $token = $data['token'] ?? $data['access_token'] ?? $data['data']['token'] ?? null;

        if ($token === null) {
            throw new \RuntimeException('Login response did not contain a token.');
        }

        return $token;
    }

    /**
     * Parse categories with AI (same as CategoryStoreWorkflow).
     *
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private function parseCategoriesWithAi(string $description): array
    {
        $systemPrompt = <<<PROMPT
You are a restaurant category parser. You receive a list of category names (in any language or format).

You must output a JSON object with this EXACT structure. Use ONLY valid JSON, no markdown or extra text:

{
  "categories": {
    "category1": {
      "name_ar": "...",
      "name_en": "...",
      "name_he": "..."
    },
    "category2": {
      "name_ar": "...",
      "name_en": "...",
      "name_he": "..."
    }
  }
}

Rules:
- name_en: the category name from input or sensible English translation.
- name_ar: Arabic translation of the category name.
- name_he: Hebrew translation of the category name.
- Use category1, category2, category3... as keys.
- Output ONLY the JSON object, no other text.
PROMPT;

        $userPrompt = "Categories to parse:\n{$description}";

        $aiResponse = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => 4096]);
        $categories = $this->extractCategoriesFromAiResponse($aiResponse);

        if (empty($categories)) {
            throw new \RuntimeException('AI did not return valid categories. Response: ' . substr($aiResponse, 0, 500));
        }

        return $categories;
    }

    /**
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private function extractCategoriesFromAiResponse(string $response): array
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"categories"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (!is_array($decoded) || !isset($decoded['categories']) || !is_array($decoded['categories'])) {
            return [];
        }

        $categories = [];
        $required = ['name_ar', 'name_en', 'name_he'];

        foreach ($decoded['categories'] as $key => $cat) {
            if (!is_array($cat)) {
                continue;
            }

            $normalized = [];
            foreach ($required as $field) {
                $normalized[$field] = (string) ($cat[$field] ?? '');
            }

            $categories[$key] = $normalized;
        }

        return $categories;
    }

    private function describeLogoStyle(string $logoPath): ?string
    {
        try {
            $systemPrompt = <<<'PROMPT'
Look at the logo image. Describe only what is needed so another image can match it:
the same theme colors and the same overall shape and graphic style (how forms and outlines look).
Keep it short—one or two sentences.
PROMPT;
            $style = trim($this->analyzeImageWithSystem(
                $systemPrompt,
                $logoPath,
                'Here is the logo.',
                ['max_tokens' => 200],
            ));
            return $style !== '' ? $style : null;
        } catch (\Throwable $e) {
            Log::warning('CategoryStoreWithAiImageWorkflow logo style analysis failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string}>  $categories
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, image_path?: string|null}>
     */
    private function generateImagesForCategories(array $categories, string $restaurantName, ?string $styleDescription): array
    {
        $result = [];
        $subdomain = $this->toSubdomain($restaurantName);
        $baseDir = storage_path('app/category-ai-images/' . $subdomain);

        $count = 0;
        $maxImages = 40;

        foreach ($categories as $key => $cat) {
            $copy = $cat;
            $copy['image_path'] = null;

            if ($count >= $maxImages) {
                $result[$key] = $copy;
                continue;
            }

            $nameEn = $cat['name_en'] ?? ($cat['name_ar'] ?? $key);
            $slug = Str::slug($nameEn) ?: ('category-' . $key);
            $savePath = $baseDir . '/' . $slug . '.png';

            $prompt = "Menu category image for \"{$nameEn}\". No text or letters in the image.";
            if ($styleDescription) {
                $prompt .= " Use the same theme colors and the same shape style as this logo analysis: {$styleDescription}";
            }

            try {
                if ($this->generateCategoryImage($prompt, $savePath)) {
                    $copy['image_path'] = $savePath;
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::warning('CategoryStoreWithAiImageWorkflow image generation failed', [
                    'category' => $nameEn,
                    'error' => $e->getMessage(),
                ]);
            }

            $result[$key] = $copy;
        }

        return $result;
    }

    /**
     * Create each category via the Kaman API, attaching the generated image when present.
     *
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, image_path?: string|null}>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: array<int, array{key: string, id?: mixed}>, failed: array<int, array{key: string, error: string}>}
     */
    private function createCategories(string $baseUrl, string $token, array $categories, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($categories);
        $i = 0;
        $timeout = 90;

        foreach ($categories as $key => $category) {
            $i++;
            $progress && $progress('category', 'Creating category ' . $i . '/' . $total . ': ' . ($category['name_en'] ?? $key), ['key' => $key]);

            $body = [
                'name_ar' => $category['name_ar'],
                'name_en' => $category['name_en'],
                'name_he' => $category['name_he'],
            ];

            try {
                $http = $this->http($timeout)->withToken($token);
                if (!empty($category['image_path']) && File::exists($category['image_path'])) {
                    $http = $http->attach('image', File::get($category['image_path']), File::basename($category['image_path']));
                }
                $response = $http->post("{$baseUrl}/api/manager/categories", $body);
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'error' => $e->getMessage()];
                Log::warning('CategoryStoreWithAiImageWorkflow category request failed', ['key' => $key, 'error' => $e->getMessage()]);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();
                $created[] = [
                    'key' => $key,
                    'id' => $data['data']['id'] ?? $data['id'] ?? $data['category']['id'] ?? null,
                ];
            } else {
                $bodyJson = $response->json();
                $message = $bodyJson['message'] ?? $bodyJson['error'] ?? $response->body();
                $failed[] = [
                    'key' => $key,
                    'error' => is_string($message) ? $message : json_encode($message),
                ];
                Log::warning('CategoryStoreWithAiImageWorkflow category creation failed', [
                    'key' => $key,
                    'status' => $response->status(),
                    'response' => $message,
                ]);
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private function generateCategoryImage(string $prompt, string $savePath): bool
    {
        $apiKey = trim((string) (config('openai.api_key') ?? ''));
        $baseUrl = rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/');
        $timeout = (int) (config('openai.request_timeout', 30) ?: 60);
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);

        $http = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->baseUrl($baseUrl);

        if (config('openai.organization')) {
            $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
        }
        if (!$sslVerify) {
            $http = $http->withOptions(['verify' => false]);
        }

        $response = $http->post('/images/generations', [
            'model' => 'gpt-5.3-chat-latest',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
        ]);

        if (!$response->successful()) {
            Log::warning('CategoryStoreWithAiImageWorkflow image generation failed', [
                'model' => 'gpt-5.3-chat-latest',
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        $json = $response->json();
        $b64 = $json['data'][0]['b64_json'] ?? null;
        if ($b64 === null || $b64 === '') {
            return false;
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            return false;
        }

        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($savePath, $decoded) !== false;
    }

    private function toSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        
        $subdomain = trim($subdomain, '-');
        $subdomain = preg_replace('/-+/', '-', $subdomain);

        return $subdomain ?: 'default';
    }
}

