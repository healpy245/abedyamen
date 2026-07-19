<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;

use App\Services\AI\KamanMealItemsCreator;
use App\Services\AI\KamanMenuCategoryEnsurer;
use App\Services\AI\MealImageFilenameHelper;
use App\Services\AI\StructuredCategoryBlocksParser;
use App\Services\AI\StructuredMealsParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CustomImagesMealsStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');
        $folderName = trim($payload['folder_name'] ?? '');
        $imageNames = $payload['image_names'] ?? [];

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

        if (empty($imageNames)) {
            return [
                'success' => false,
                'error' => 'At least one meal image is required.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));

        set_time_limit(600);

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->login($baseUrl, $loginEmail, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching categories...', []);
            $categories = $this->fetchCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' categories', ['count' => count($categories)]);

            KamanMenuCategoryEnsurer::ensureFromDescription(
                $baseUrl,
                $token,
                $description,
                $categories,
                $payload,
                fn (string $system, string $user, array $options = []) => $this->chat($system, $user, $options),
                $progress
            );

            $meals = $this->parseMealsFromDescription($description, $categories, $progress);

            $progress('match', 'Matching each meal to its image by filename...', []);
            $mealsWithImages = $this->matchMealsToUploadedImages($meals, $folderName, $imageNames);
            $mealsWithImages = $this->localizeMenuRecords($mealsWithImages, $payload, $progress);
            $matchedCount = count(array_filter($mealsWithImages, fn ($m) => !empty($m['image_path'])));
            $progress('match', 'Matched ' . $matchedCount . ' meals with images', ['matched' => $matchedCount, 'total' => count($meals)]);

            $progress('items', 'Creating items via Kaman API...', []);
            $itemsResult = KamanMealItemsCreator::create(
                $baseUrl,
                $token,
                $mealsWithImages,
                $progress,
                4,
                'CustomImagesMealsStoreWorkflow'
            );
            $progress('items', 'Created ' . count($itemsResult['created']) . ' items, ' . count($itemsResult['failed']) . ' failed', $itemsResult);

            Log::info('CustomImagesMealsStoreWorkflow completed', [
                'restaurant' => $restaurantName,
                'meals_count' => count($meals),
                'items_created' => $itemsResult['created'],
                'items_failed' => $itemsResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Custom images meals store processed successfully',
                'data' => [
                    'token' => $token,
                    'meals' => $mealsWithImages,
                    'items_created' => $itemsResult['created'],
                    'items_failed' => $itemsResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('CustomImagesMealsStoreWorkflow failed', [
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
        $response = $this->http(30)->post("{$baseUrl}/login", [
            'email' => $email,
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
        $response = $this->http(30)->withToken($token)->get("{$baseUrl}/categories");
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
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    private function parseMealsFromDescription(string $description, array $categories, ?callable $progress = null): array
    {
        $parsed = StructuredCategoryBlocksParser::parseStrict($description);

        if ($parsed['ok']) {
            try {
                $meals = StructuredMealsParser::parseBlocks($parsed['blocks'], $categories);
                if ($meals !== []) {
                    $progress && $progress('parse', 'Parsed ' . count($meals) . ' meals', ['count' => count($meals)]);

                    return $meals;
                }
            } catch (\RuntimeException $e) {
                throw $e;
            }
        }

        $progress && $progress('ai', 'Parsing meals with AI (fallback)...', []);
        $meals = $this->parseMealsWithAi($description, $categories);
        $progress && $progress('ai', 'Parsed ' . count($meals) . ' meals', ['count' => count($meals)]);

        return $meals;
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
- name_en: MUST be identical to the meal name from the input (same spelling and spacing) so uploaded image filenames can be matched.
- name_ar: Arabic translation of the meal name, WITHOUT tashkeel/diacritics.
- name_he: Hebrew translation of the meal name.
- price: from input as string (e.g. "25.00"). If missing, blank, or only whitespace after ":", use "0.00".
- description_ar, description_en, description_he: brief 1-line description of the meal in each language. Can be empty string if no description.
- `description_ar` must also be WITHOUT Arabic tashkeel/diacritics.
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
            $normalized['price'] = $this->normalizeExtractedPrice($normalized['price']);
            $meals[$key] = $normalized;
        }
        return $meals;
    }

    /**
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>  $meals
     * @param  array<int, string>  $imageNames  Uploaded filenames in public/{folderName}/
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string, image_path?: string|null}>
     */
    private function matchMealsToUploadedImages(array $meals, string $folderName, array $imageNames): array
    {
        $basePath = public_path($folderName);
        $absolutePaths = [];

        foreach ($imageNames as $name) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $name;
            if (File::exists($fullPath)) {
                $absolutePaths[] = $fullPath;
            }
        }

        return MealImageFilenameHelper::attachImagesByFilename($meals, $absolutePaths);
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
