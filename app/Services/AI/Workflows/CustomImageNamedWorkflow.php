<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CustomImageNamedWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');
        $folderName = trim($payload['folder_name'] ?? '');
        $imagePaths = $payload['image_paths'] ?? [];

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

        if (empty($imagePaths)) {
            return [
                'success' => false,
                'error' => 'At least one image is required in the folder.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
<<<<<<< HEAD
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));
=======
        $baseUrl = "https://{$subdomain}.kaman.rest";
>>>>>>> parent of cd712ea (First)

        set_time_limit(600);

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->login($baseUrl, $loginEmail, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching categories...', []);
            $categories = $this->fetchCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' categories', ['count' => count($categories)]);

            $progress('ai', 'Parsing meals with AI...', []);
            $meals = $this->parseMealsWithAi($description, $categories);
            $progress('ai', 'Parsed ' . count($meals) . ' meals', ['count' => count($meals)]);

            $progress('match', 'Matching meals to images...', []);
            $mealsWithImages = $this->matchMealsToImages($meals, $imagePaths);
            $progress('match', 'Matched ' . count(array_filter($mealsWithImages, fn ($m) => !empty($m['image_path']))) . ' meals with images', []);

            $progress('items', 'Creating items via Kaman API...', []);
            $itemsResult = $this->createItems($baseUrl, $token, $mealsWithImages, $progress);
            $progress('items', 'Created ' . count($itemsResult['created']) . ' items, ' . count($itemsResult['failed']) . ' failed', $itemsResult);

            Log::info('CustomImageNamedWorkflow completed', [
                'restaurant' => $restaurantName,
                'meals_count' => count($meals),
                'items_created' => $itemsResult['created'],
                'items_failed' => $itemsResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Custom image named meals processed successfully',
                'data' => [
                    'token' => $token,
                    'meals' => $mealsWithImages,
                    'items_created' => $itemsResult['created'],
                    'items_failed' => $itemsResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('CustomImageNamedWorkflow failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);
            throw $e;
        }
    }

    private function http(int $timeout = 90): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout($timeout)->acceptJson();
        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }
        return $http;
    }

    private function login(string $baseUrl, string $email, string $password): string
    {
<<<<<<< HEAD
        $response = $this->http(30)->post("{$baseUrl}/login", [
            'email' => $email,
=======
        $response = $this->http(30)->post("{$baseUrl}/api/manager/login", [
            'email' => "{$subdomain}@kaman.rest",
>>>>>>> parent of cd712ea (First)
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

    private function fetchCategories(string $baseUrl, string $token): array
    {
        $response = $this->http(30)->withToken($token)->get("{$baseUrl}/api/manager/categories");
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
     * @param  array<int, array{id?: mixed, name?: string, name_ar?: string, name_en?: string}>  $categories
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
- name_en: the meal name from input (or sensible English translation). Keep it simple and close to the original for image matching.
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
     * @param  array<int, array{id?: mixed, name?: string, name_ar?: string, name_en?: string}>  $categories
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
     * Match each meal to an image by comparing meal name_en to image filename (without extension).
     * Normalizes both: lowercase, remove spaces/dashes/underscores for comparison.
     *
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>  $meals
     * @param  array<int, string>  $imagePaths  Relative paths like "folderName/caesar salad.jpg"
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string, image_path?: string}>
     */
    private function matchMealsToImages(array $meals, array $imagePaths): array
    {
        $imageMap = [];
        foreach ($imagePaths as $relPath) {
            $relPath = str_replace('\\', '/', trim((string) $relPath));
            if ($relPath === '') {
                continue;
            }
            $fullPath = public_path($relPath);
            if (!File::exists($fullPath)) {
                continue;
            }
            $basename = pathinfo($relPath, PATHINFO_FILENAME);
            $keyNorm = $this->normalizeForMatch($basename);
            if ($keyNorm !== '') {
                $imageMap[$keyNorm] = $fullPath;
            }
        }

        $result = [];
        foreach ($meals as $mealKey => $meal) {
            $mealCopy = $meal;
            $mealCopy['image_path'] = null;

            $nameEn = trim($meal['name_en'] ?? '');
            if ($nameEn === '') {
                $result[$mealKey] = $mealCopy;
                continue;
            }

            $mealNorm = $this->normalizeForMatch($nameEn);
            if ($mealNorm !== '' && isset($imageMap[$mealNorm])) {
                $mealCopy['image_path'] = $imageMap[$mealNorm];
            }
            $result[$mealKey] = $mealCopy;
        }

        return $result;
    }

    private function normalizeForMatch(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[\s\-_]+/', '', $s);
        return $s ?? '';
    }

    /**
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
                Log::warning('CustomImageNamedWorkflow item request failed', ['key' => $key, 'error' => $e->getMessage()]);
                continue;
            }

            if ($response->successful()) {
                $data = $response->json();
                $created[] = ['key' => $key, 'id' => $data['data']['id'] ?? $data['id'] ?? $data['item']['id'] ?? null];
            } else {
                $message = $response->json('message') ?? $response->json('error') ?? $response->body();
                $failed[] = ['key' => $key, 'error' => is_string($message) ? $message : json_encode($message)];
                Log::warning('CustomImageNamedWorkflow item creation failed', ['key' => $key, 'status' => $response->status(), 'response' => $message]);
            }
        }

        return ['created' => $created, 'failed' => $failed];
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
