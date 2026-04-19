<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class MealStoreWithAiImagesWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');
        $styleImagePath = isset($payload['meal_style_image_path']) && is_string($payload['meal_style_image_path'])
            ? $payload['meal_style_image_path']
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
                'error' => 'Description with meals is required.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = "https://{$subdomain}.kaman.rest";

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $token = $this->login($baseUrl, $subdomain, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching categories...', []);
            $categories = $this->fetchCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' categories', ['count' => count($categories)]);

            $progress('ai', 'Parsing meals with AI...', []);
            $meals = $this->parseMealsWithAi($description, $categories);
            $progress('ai', 'Parsed ' . count($meals) . ' meals', ['count' => count($meals)]);

            $progress('images', 'Generating AI images for meals...', []);
            $mealsWithImages = $this->generateImagesForMeals($meals, $restaurantName, $styleImagePath);
            $withImageCount = count(array_filter($mealsWithImages, fn ($m) => !empty($m['image_path'] ?? null)));
            $progress('images', 'Generated images for ' . $withImageCount . ' meals', ['with_images' => $withImageCount]);

            $progress('items', 'Creating items (with images) via Kaman API...', []);
            $itemsResult = $this->createItems($baseUrl, $token, $mealsWithImages, $progress);
            $progress('items', 'Created ' . count($itemsResult['created']) . ' items, ' . count($itemsResult['failed']) . ' failed', $itemsResult);

            Log::info('MealStoreWithAiImagesWorkflow completed', [
                'restaurant' => $restaurantName,
                'meals_count' => count($mealsWithImages),
                'items_created' => $itemsResult['created'],
                'items_failed' => $itemsResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Meal store (with AI images) processed successfully',
                'data' => [
                    'token' => $token,
                    'meals' => $mealsWithImages,
                    'items_created' => $itemsResult['created'],
                    'items_failed' => $itemsResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('MealStoreWithAiImagesWorkflow failed', [
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

            Log::warning('MealStoreWithAiImagesWorkflow login failed', [
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
     * @return array<int, array{id: int|string, name: string, name_ar?: string, name_en?: string, name_he?: string}>
     */
    private function fetchCategories(string $baseUrl, string $token): array
    {
        $response = $this->http()
            ->withToken($token)
            ->get("{$baseUrl}/api/manager/categories");

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

    /**
     * Create each meal as an item via the Kaman API, attaching the generated image when available.
     *
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string, image_path?: string|null}>  $meals
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: array<int, array{key: string, id?: mixed}>, failed: array<int, array{key: string, error: string}>}
     */
    private function createItems(string $baseUrl, string $token, array $meals, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($meals);
        $i = 0;
        $timeout = 90;

        foreach ($meals as $key => $meal) {
            $i++;
            $progress && $progress('item', 'Creating item ' . $i . '/' . $total . ': ' . ($meal['name_en'] ?? $key), ['key' => $key]);

            $body = [
                'name_ar' => $meal['name_ar'],
                'name_en' => $meal['name_en'],
                'name_he' => $meal['name_he'],
                'price' => $meal['price'],
                'category_id' => $meal['category_id'],
                'description_ar' => $meal['description_ar'],
                'description_en' => $meal['description_en'],
                'description_he' => $meal['description_he'],
            ];

            try {
                $http = $this->http($timeout)->withToken($token);
                if (!empty($meal['image_path']) && File::exists($meal['image_path'])) {
                    $http = $http->attach('image', File::get($meal['image_path']), File::basename($meal['image_path']));
                }
                $response = $http->post("{$baseUrl}/api/manager/items", $body);
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'error' => $e->getMessage()];
                Log::warning('MealStoreWithAiImagesWorkflow item request failed', ['key' => $key, 'error' => $e->getMessage()]);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();
                $created[] = [
                    'key' => $key,
                    'id' => $data['data']['id'] ?? $data['id'] ?? $data['item']['id'] ?? null,
                ];
            } else {
                $bodyJson = $response->json();
                $message = $bodyJson['message'] ?? $bodyJson['error'] ?? $response->body();
                $failed[] = [
                    'key' => $key,
                    'error' => is_string($message) ? $message : json_encode($message),
                ];
                Log::warning('MealStoreWithAiImagesWorkflow item creation failed', [
                    'key' => $key,
                    'status' => $response->status(),
                    'response' => $message,
                ]);
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @param  array<int, array{id: int|string, name?: string, name_ar?: string, name_en?: string}>  $categories
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    private function parseMealsWithAi(string $description, array $categories): array
    {
        $categoryList = $this->formatCategoriesForPrompt($categories);

        $systemPrompt = <<<PROMPT
You are a restaurant menu parser. You receive a meal list in the format:

category name : {
meal name : price
meal name : price
...
}

You must output a JSON object with this EXACT structure. Use ONLY valid JSON, no markdown or extra text:

{
  "meals": {
    "meal1": {
      "name_ar": "...",
      "name_en": "...",
      "name_he": "...",
      "price": "...",
      "category_id": "...",
      "description_ar": "...",
      "description_en": "...",
      "description_he": "..."
    },
    "meal2": { ... }
  }
}

Rules:
- Assign category_id from the available categories list. Match the input category name to the closest category. Use the id as string (e.g. "1", "2").
- name_en: the meal name from input (or sensible English translation).
- name_ar: Arabic translation of the meal name.
- name_he: Hebrew translation of the meal name.
- price: the price from input as string (e.g. "25.00").
- description_ar, description_en, description_he: brief 1-line description of the meal in each language. Can be empty string if no description.
- Use meal1, meal2, meal3... as keys.
- Output ONLY the JSON object, no other text.
PROMPT;

        $userPrompt = "Available categories (use id for category_id):\n{$categoryList}\n\nMeals to parse:\n{$description}";

        $aiResponse = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => 8192]);
        $meals = $this->extractMealsFromAiResponse($aiResponse);

        if (empty($meals)) {
            throw new \RuntimeException('AI did not return valid meals. Response: ' . substr($aiResponse, 0, 500));
        }

        return $meals;
    }

    /**
     * @param  array<int, array{id: int|string, name?: string, name_ar?: string, name_en?: string}>  $categories
     */
    private function formatCategoriesForPrompt(array $categories): string
    {
        $lines = [];

        foreach ($categories as $cat) {
            $id = $cat['id'] ?? $cat['category_id'] ?? '?';
            $name = $cat['name'] ?? $cat['name_en'] ?? $cat['name_ar'] ?? (string) $id;
            $lines[] = "- id: {$id}, name: {$name}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    private function extractMealsFromAiResponse(string $response): array
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"meals"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (!is_array($decoded) || !isset($decoded['meals']) || !is_array($decoded['meals'])) {
            return [];
        }

        $meals = [];
        $required = ['name_ar', 'name_en', 'name_he', 'price', 'category_id', 'description_ar', 'description_en', 'description_he'];

        foreach ($decoded['meals'] as $key => $meal) {
            if (!is_array($meal)) {
                continue;
            }

            $normalized = [];
            foreach ($required as $field) {
                $normalized[$field] = (string) ($meal[$field] ?? '');
            }

            $meals[$key] = $normalized;
        }

        return $meals;
    }

    /**
     * Generate a DALL-E image for each meal and attach it as image_path when successful.
     *
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>  $meals
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string, image_path?: string|null}>
     */
    private function generateImagesForMeals(array $meals, string $restaurantName, ?string $styleImagePath = null): array
    {
        $result = [];
        $subdomain = $this->toSubdomain($restaurantName);
        $baseDir = storage_path('app/meal-ai-images/' . $subdomain);

        $styleDescription = null;
        if ($styleImagePath && is_string($styleImagePath) && file_exists($styleImagePath)) {
            try {
                $stylePrompt = 'Describe this food photo in one short sentence: main colors, background, plating style, camera angle, and mood.';
                $styleDescription = trim($this->analyzeImage($styleImagePath, $stylePrompt, ['max_tokens' => 150]));
            } catch (\Throwable $e) {
                Log::warning('MealStoreWithAiImagesWorkflow style image analysis failed', [
                    'path' => $styleImagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $count = 0;
        $maxImages = 40; // hard cap to avoid long runs

        foreach ($meals as $key => $meal) {
            $mealCopy = $meal;
            $mealCopy['image_path'] = null;

            if ($count >= $maxImages) {
                $result[$key] = $mealCopy;
                continue;
            }

            $nameEn = $meal['name_en'] ?? ($meal['name_ar'] ?? $key);
            $slug = Str::slug($nameEn) ?: ('meal-' . $key);
            $savePath = $baseDir . '/' . $slug . '.png';

            $prompt = "High-quality, realistic photograph of the restaurant dish \"{$nameEn}\" on a simple neutral background. "
                . "Well-lit, appetizing, professional food photography, no text, no watermark, no logo.";

            if ($styleDescription) {
                $prompt .= " Match this style: {$styleDescription}.";
            }

            try {
                if ($this->generateMealImage($prompt, $savePath)) {
                    $mealCopy['image_path'] = $savePath;
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::warning('MealStoreWithAiImagesWorkflow image generation failed', [
                    'meal' => $nameEn,
                    'error' => $e->getMessage(),
                ]);
            }

            $result[$key] = $mealCopy;
        }

        return $result;
    }

    private function generateMealImage(string $prompt, string $savePath): bool
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

        $models = array_values(array_unique([
            config('openai.image_model', 'gpt-image-1'),
            'dall-e-3',
        ]));

        foreach ($models as $model) {
            $response = $http->post('/images/generations', [
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'b64_json',
            ]);

            if (!$response->successful()) {
                Log::warning('MealStoreWithAiImagesWorkflow image generation failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                continue;
            }

            $body = $response->json();
            $b64 = $body['data'][0]['b64_json'] ?? null;
            if ($b64 === null || $b64 === '') {
                continue;
            }

            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                continue;
            }

            $dir = dirname($savePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents($savePath, $decoded) !== false;
        }

        return false;
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

