<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;

use App\Services\AI\StructuredCategoryBlocksParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stores ingredients and assigns each to an existing ingredients category by name in the description.
 *
 * Input (multiple categories supported):
 *   Toppings : { cheese : 5 }
 *   Sauces : { ketchup : 2 }
 */
final class IngredientsStoreWorkflow extends AbstractFormWorkflow
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
                'error' => 'Description with ingredients is required.',
            ];
        }

        try {
            @set_time_limit((int) config('openai.workflow_max_execution_time', 1800));
        } catch (\Throwable $e) {
            // ignore
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain);

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $token = $this->login($baseUrl, $subdomain, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching ingredients categories...', []);
            $categories = $this->fetchIngredientsCategories($baseUrl, $token);
            $progress('categories', 'Fetched ' . count($categories) . ' ingredients categories', ['count' => count($categories)]);

            if ($categories === []) {
                return [
                    'success' => false,
                    'error' => 'No ingredients categories found on this restaurant. Create categories first, then use Ingredients Store.',
                ];
            }

            $progress('ai', 'Parsing ingredients with AI (by category blocks)...', []);
            $ingredients = $this->parseIngredientsFromDescription($description, $categories, $progress);
            $progress('ai', 'Parsed ' . count($ingredients) . ' ingredients', ['count' => count($ingredients)]);
            $ingredients = $this->localizeMenuRecords($ingredients, $payload, $progress);

            if ($ingredients === []) {
                return [
                    'success' => false,
                    'error' => 'No ingredients could be parsed. Use format: Category Name : { ingredient name : price }',
                ];
            }

            $progress('ingredients', 'Creating ingredients via Kaman API...', []);
            $createResult = $this->createIngredients($baseUrl, $token, $ingredients, $progress);
            $progress('ingredients', 'Created ' . count($createResult['created']) . ' ingredients, ' . count($createResult['failed']) . ' failed', $createResult);

            Log::info('IngredientsStoreWorkflow completed', [
                'restaurant' => $restaurantName,
                'ingredients_count' => count($ingredients),
                'ingredients_created' => $createResult['created'],
                'ingredients_failed' => $createResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Ingredients store processed successfully',
                'data' => [
                    'token' => $token,
                    'ingredients' => $ingredients,
                    'ingredients_created' => $createResult['created'],
                    'ingredients_failed' => $createResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('IngredientsStoreWorkflow failed', [
                'error' => $e->getMessage(),
                'restaurant' => $restaurantName,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>
     */
    private function parseIngredientsFromDescription(string $description, array $categories, ?callable $progress = null): array
    {
        $parsed = StructuredCategoryBlocksParser::parseStrict($description);

        if ($parsed['ok']) {
            return $this->parseIngredientsByCategoryBlocks($parsed['blocks'], $categories, $progress);
        }

        $progress && $progress('ai', 'Parsing entire ingredients list in one pass...', []);

        return $this->parseIngredientsWithAi($description, $categories, 'batch');
    }

    /**
     * @param  list<array{label: string, body: string}>  $blocks
     * @param  array<int, array<string, mixed>>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>
     */
    private function parseIngredientsByCategoryBlocks(array $blocks, array $categories, ?callable $progress = null): array
    {
        $allIngredients = [];
        $ingredientIndex = 0;
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

            $progress && $progress('ai', "Parsing ingredients block {$blockNum}/{$totalBlocks}: {$label}", [
                'category' => $label,
            ]);

            $keyPrefix = 'block' . $blockNum . '_';
            $chunkIngredients = $this->parseIngredientsWithAi($chunk, $categories, $keyPrefix);

            foreach ($chunkIngredients as $ingredient) {
                $ingredientIndex++;
                $allIngredients['ingredient' . $ingredientIndex] = $ingredient;
            }
        }

        return $allIngredients;
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

    private function login(string $baseUrl, string $subdomain, string $password): string
    {
        $response = $this->http()->post("{$baseUrl}/login", [
            'email' => KamanUrl::loginEmail($subdomain),
            'password' => $password,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            Log::warning('IngredientsStoreWorkflow login failed', [
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
    private function fetchIngredientsCategories(string $baseUrl, string $token): array
    {
        $response = $this->http()
            ->withToken($token)
            ->get("{$baseUrl}/ingredients-categories");

        if (!$response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();

            throw new \RuntimeException('Failed to fetch ingredients categories: ' . (is_string($message) ? $message : json_encode($message)));
        }

        $data = $response->json();
        $list = $data['data'] ?? $data['categories'] ?? $data['ingredients_categories'] ?? $data;

        if (!is_array($list)) {
            throw new \RuntimeException('Ingredients categories response format is invalid.');
        }

        return $list;
    }

    /**
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>  $ingredients
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: array<int, array{key: string, id?: mixed}>, failed: array<int, array{key: string, error: string}>}
     */
    private function createIngredients(string $baseUrl, string $token, array $ingredients, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($ingredients);
        $i = 0;

        foreach ($ingredients as $key => $ingredient) {
            $i++;
            $progress && $progress('ingredient', 'Creating ingredient ' . $i . '/' . $total . ': ' . ($ingredient['name_en'] ?? $key), ['key' => $key]);

            $response = $this->http()
                ->withToken($token)
                ->post("{$baseUrl}/ingredients", $ingredient);

            if ($response->successful()) {
                $data = $response->json();
                $created[] = [
                    'key' => $key,
                    'id' => $data['data']['id'] ?? $data['id'] ?? $data['ingredient']['id'] ?? null,
                ];
            } else {
                $body = $response->json();
                $message = $body['message'] ?? $body['error'] ?? $response->body();
                $failed[] = [
                    'key' => $key,
                    'error' => is_string($message) ? $message : json_encode($message),
                ];
                Log::warning('IngredientsStoreWorkflow ingredient creation failed', [
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
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>
     */
    private function parseIngredientsWithAi(string $description, array $categories, string $keyPrefix = 'ingredient'): array
    {
        $categoryList = $this->formatCategoriesForPrompt($categories);

        $systemPrompt = <<<PROMPT
You are a restaurant ingredients parser. You receive an ingredients list in the format:

category name : {
ingredient name : price
ingredient name : price
...
}

Ingredients categories in the input already exist on the restaurant — do NOT create categories. Only output ingredients.

You must output a JSON object with this EXACT structure. Use ONLY valid JSON, no markdown or extra text.
NO description fields - only name_ar, name_en, name_he, price, category_id.

{
  "ingredients": {
    "ingredient1": {
      "name_ar": "...",
      "name_en": "...",
      "name_he": "...",
      "price": "...",
      "category_id": "..."
    }
  }
}

Rules:
- Match each block's category name to the closest name in the available ingredients categories list. Set category_id to that category's id (as string).
- name_en: ingredient name from input (or sensible English translation).
- name_ar: Arabic translation WITHOUT tashkeel/diacritics.
- name_he: Hebrew translation.
- price: from input as string (e.g. "5.00"). If missing, blank, or only whitespace after ":", use "0.00".
- Use ingredient1, ingredient2, ingredient3... as keys.
- Output ONLY the JSON object.
PROMPT;

        $userPrompt = "Available ingredients categories (use id for category_id):\n{$categoryList}\n\nIngredients to parse:\n{$description}";

        $maxTokens = (int) config('openai.ingredients_store_max_tokens', config('openai.meal_store_max_tokens', 16384));

        $aiResponse = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => $maxTokens]);
        $ingredients = $this->extractIngredientsFromAiResponse($aiResponse);

        if ($ingredients === []) {
            throw new \RuntimeException('AI did not return valid ingredients. Response: ' . substr($aiResponse, 0, 500));
        }

        if ($keyPrefix !== 'ingredient') {
            $renamed = [];
            $i = 0;
            foreach ($ingredients as $ingredient) {
                $i++;
                $renamed[$keyPrefix . $i] = $ingredient;
            }

            return $renamed;
        }

        return $ingredients;
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
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>
     */
    private function extractIngredientsFromAiResponse(string $response): array
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"ingredients"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (!is_array($decoded) || !isset($decoded['ingredients']) || !is_array($decoded['ingredients'])) {
            return [];
        }

        $ingredients = [];
        $required = ['name_ar', 'name_en', 'name_he', 'price', 'category_id'];

        foreach ($decoded['ingredients'] as $key => $ing) {
            if (!is_array($ing)) {
                continue;
            }

            $normalized = [];
            foreach ($required as $field) {
                $normalized[$field] = (string) ($ing[$field] ?? '');
            }

            $normalized['price'] = $this->normalizeExtractedPrice($normalized['price']);

            if ($normalized['name_en'] === '' && $normalized['name_ar'] === '') {
                continue;
            }

            $ingredients[$key] = $normalized;
        }

        return $ingredients;
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
