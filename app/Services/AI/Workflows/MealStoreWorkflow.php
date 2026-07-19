<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;

use App\Services\AI\StructuredCategoryBlocksParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stores meals and assigns each to an existing category by matching the category name in the description.
 *
 * Input (multiple categories supported):
 *   Burgers : { cheeseburger : 50 }
 *   Drinks : { cola : 10 }
 */
final class MealStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        $restaurantName = trim($payload['restaurant_name'] ?? '');
        $password = $payload['password'] ?? '';
        $description = trim($payload['description'] ?? '');

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

        try {
            @set_time_limit((int) config('openai.workflow_max_execution_time', 1800));
        } catch (\Throwable $e) {
            // ignore
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->login($baseUrl, $loginEmail, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching categories...', []);
            $categories = $this->fetchCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' categories', ['count' => count($categories)]);

            if ($categories === []) {
                return [
                    'success' => false,
                    'error' => 'No categories found on this restaurant. Create categories first, then use Meal Store.',
                ];
            }

            $progress('ai', 'Parsing meals with AI (by category blocks)...', []);
            $meals = $this->parseMealsFromDescription($description, $categories, $progress);
            $progress('ai', 'Parsed ' . count($meals) . ' meals', ['count' => count($meals)]);
            $meals = $this->localizeMenuRecords($meals, $payload, $progress);

            if ($meals === []) {
                return [
                    'success' => false,
                    'error' => 'No meals could be parsed. Use format: Category Name : { meal name : price }',
                ];
            }

            $progress('items', 'Creating items via Kaman API...', []);
            $itemsResult = $this->createItems($baseUrl, $token, $meals, $progress);
            $progress('items', 'Created ' . count($itemsResult['created']) . ' items, ' . count($itemsResult['failed']) . ' failed', $itemsResult);

            Log::info('MealStoreWorkflow completed', [
                'restaurant' => $restaurantName,
                'meals_count' => count($meals),
                'items_created' => $itemsResult['created'],
                'items_failed' => $itemsResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Meal store processed successfully',
                'data' => [
                    'token' => $token,
                    'meals' => $meals,
                    'items_created' => $itemsResult['created'],
                    'items_failed' => $itemsResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('MealStoreWorkflow failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);

            throw $e;
        }
    }

    /**
     * Parse all meals; splits structured input by category block for large menus.
     *
     * @param  array<int, array<string, mixed>>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    private function parseMealsFromDescription(string $description, array $categories, ?callable $progress = null): array
    {
        $parsed = StructuredCategoryBlocksParser::parseStrict($description);

        if ($parsed['ok']) {
            return $this->parseMealsByCategoryBlocks($parsed['blocks'], $categories, $progress);
        }

        $progress && $progress('ai', 'Parsing entire menu in one pass...', []);

        return $this->parseMealsWithAi($description, $categories, 'batch');
    }

    /**
     * @param  list<array{label: string, body: string}>  $blocks
     * @param  array<int, array<string, mixed>>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>
     */
    private function parseMealsByCategoryBlocks(array $blocks, array $categories, ?callable $progress = null): array
    {
        $allMeals = [];
        $mealIndex = 0;
        $blockNum = 0;
        $totalBlocks = count($blocks);

        foreach ($blocks as $block) {
            $body = trim($block['body']);
            if ($body === '') {
                continue;
            }

            $blockNum++;
            $label = trim($block['label']);
            $chunk = $label . ' : {' . "\n" . $body . "\n" . '}';

            $progress && $progress('ai', "Parsing category block {$blockNum}/{$totalBlocks}: {$label}", [
                'category' => $label,
            ]);

            $keyPrefix = 'block' . $blockNum . '_';
            $chunkMeals = $this->parseMealsWithAi($chunk, $categories, $keyPrefix);

            foreach ($chunkMeals as $meal) {
                $mealIndex++;
                $allMeals['meal' . $mealIndex] = $meal;
            }
        }

        return $allMeals;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = (int) config('openai.request_timeout', 600);

        $http = Http::timeout($timeout)->connectTimeout(30)->acceptJson();

        if (!config('services.kaman.ssl_verify', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function login(string $baseUrl, string $email, string $password): string
    {
        $response = $this->http()->post("{$baseUrl}/login", [
            'email' => $email,
            'password' => $password,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            Log::warning('MealStoreWorkflow login failed', [
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
     * @return array<int, array{id: int|string, name?: string, name_ar?: string, name_en?: string, name_he?: string}>
     */
    private function fetchCategories(string $baseUrl, string $token): array
    {
        $response = $this->http()
            ->withToken($token)
            ->get("{$baseUrl}/categories");

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
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string, description_ar: string, description_en: string, description_he: string}>  $meals
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: array<int, array{key: string, id?: mixed}>, failed: array<int, array{key: string, error: string}>}
     */
    private function createItems(string $baseUrl, string $token, array $meals, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($meals);
        $i = 0;

        foreach ($meals as $key => $meal) {
            $i++;
            $progress && $progress('item', 'Creating item ' . $i . '/' . $total . ': ' . ($meal['name_en'] ?? $key), ['key' => $key]);

            $response = $this->http()
                ->withToken($token)
                ->post("{$baseUrl}/items", $meal);

            if ($response->successful()) {
                $data = $response->json();
                $created[] = [
                    'key' => $key,
                    'id' => $data['data']['id'] ?? $data['id'] ?? $data['item']['id'] ?? null,
                ];
            } else {
                $body = $response->json();
                $message = $body['message'] ?? $body['error'] ?? $response->body();
                $failed[] = [
                    'key' => $key,
                    'error' => is_string($message) ? $message : json_encode($message),
                ];
                Log::warning('MealStoreWorkflow item creation failed', [
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
    private function parseMealsWithAi(string $description, array $categories, string $keyPrefix = 'meal'): array
    {
        $categoryList = $this->formatCategoriesForPrompt($categories);

        $systemPrompt = <<<PROMPT
You are a restaurant menu parser. You receive a meal list in the format:

category name : {
meal name : price
meal name : price
...
}

Categories in the input already exist on the restaurant — do NOT create categories. Only output meals.

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
    }
  }
}

Rules:
- Match each block's category name to the closest name in the available categories list. Set category_id to that category's id (as string).
- If no reasonable match exists, skip that meal or use the closest match and note in description_en.
- name_en: meal name from input (or sensible English translation).
- name_ar: Arabic translation WITHOUT tashkeel/diacritics.
- name_he: Hebrew translation.
- price: from input as string (e.g. "50.00"). If missing, blank, or only whitespace after ":", use "0.00".
- description fields: brief one line or empty string.
- Use meal1, meal2, meal3... as keys.
- Output ONLY the JSON object.
PROMPT;

        $userPrompt = "Available categories (use id for category_id):\n{$categoryList}\n\nMeals to parse:\n{$description}";

        $maxTokens = (int) config('openai.meal_store_max_tokens', 16384);

        $aiResponse = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => $maxTokens]);
        $meals = $this->extractMealsFromAiResponse($aiResponse);

        if ($meals === []) {
            throw new \RuntimeException('AI did not return valid meals. Response: ' . substr($aiResponse, 0, 500));
        }

        if ($keyPrefix !== 'meal') {
            $renamed = [];
            $i = 0;
            foreach ($meals as $meal) {
                $i++;
                $renamed[$keyPrefix . $i] = $meal;
            }

            return $renamed;
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

            $normalized['price'] = $this->normalizeExtractedPrice($normalized['price']);

            if ($normalized['name_en'] === '' && $normalized['name_ar'] === '') {
                continue;
            }

            $meals[$key] = $normalized;
        }

        return $meals;
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
