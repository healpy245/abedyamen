<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->login($baseUrl, $loginEmail, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('categories', 'Fetching ingredients categories...', []);
            $categories = $this->fetchIngredientsCategories($baseUrl, $token);
            $progress('categories', 'Fetched '.count($categories).' categories', ['count' => count($categories)]);

            $progress('ai', 'Parsing ingredients with AI...', []);
            $ingredients = $this->parseIngredientsWithAi($description, $categories);
            $progress('ai', 'Parsed '.count($ingredients).' ingredients', ['count' => count($ingredients)]);

            $progress('ingredients', 'Creating ingredients via Kaman API...', []);
            $createResult = $this->createIngredients($baseUrl, $token, $ingredients, $progress);
            $progress('ingredients', 'Created '.count($createResult['created']).' ingredients, '.count($createResult['failed']).' failed', $createResult);

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

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout(30)->acceptJson();

        if (! config('services.kaman.ssl_verify', false)) {
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

        if (! $response->successful()) {
            $body = $response->json();
            $message = $body['message'] ?? $body['error'] ?? $response->body();

            Log::warning('IngredientsStoreWorkflow login failed', [
                'status' => $response->status(),
                'response' => $message,
            ]);

            throw new \RuntimeException('Login failed: '.(is_string($message) ? $message : json_encode($message)));
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

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();

            throw new \RuntimeException('Failed to fetch ingredients categories: '.(is_string($message) ? $message : json_encode($message)));
        }

        $data = $response->json();
        $list = $data['data'] ?? $data['categories'] ?? $data['ingredients_categories'] ?? $data;

        if (! is_array($list)) {
            throw new \RuntimeException('Ingredients categories response format is invalid.');
        }

        return $list;
    }

    /**
     * Parse ingredients from description using AI. No description fields.
     *
     * @param  array<int, array{id: int|string, name?: string, name_ar?: string, name_en?: string}>  $categories
     * @return array<string, array{name_ar: string, name_en: string, name_he: string, price: string, category_id: string}>
     */
    private function parseIngredientsWithAi(string $description, array $categories): array
    {
        $categoryList = $this->formatCategoriesForPrompt($categories);

        $systemPrompt = <<<'PROMPT'
You are a restaurant ingredients parser. You receive an ingredients list in the format:

category name : {
ingredient name : price
ingredient name : price
...
}

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
    },
    "ingredient2": { ... }
  }
}

Rules:
- Assign category_id from the available ingredients categories list. Match the input category name to the closest category. Use the id as string.
- name_en: the ingredient name from input (or sensible English translation).
- name_ar: Arabic translation of the ingredient name.
- name_he: Hebrew translation of the ingredient name.
- price: the price from input as string (e.g. "25.00").
- Do NOT include description_ar, description_en, description_he - ingredients have no description fields.
- Use ingredient1, ingredient2, ingredient3... as keys.
- Output ONLY the JSON object, no other text.
PROMPT;

        $userPrompt = "Available ingredients categories (use id for category_id):\n{$categoryList}\n\nIngredients to parse:\n{$description}";

        $aiResponse = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => 8192]);
        $ingredients = $this->extractIngredientsFromAiResponse($aiResponse);

        if (empty($ingredients)) {
            throw new \RuntimeException('AI did not return valid ingredients. Response: '.substr($aiResponse, 0, 500));
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

        if (! is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"ingredients"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (! is_array($decoded) || ! isset($decoded['ingredients']) || ! is_array($decoded['ingredients'])) {
            return [];
        }

        $ingredients = [];
        $required = ['name_ar', 'name_en', 'name_he', 'price', 'category_id'];

        foreach ($decoded['ingredients'] as $key => $ing) {
            if (! is_array($ing)) {
                continue;
            }

            $normalized = [];
            foreach ($required as $field) {
                $normalized[$field] = (string) ($ing[$field] ?? '');
            }

            $ingredients[$key] = $normalized;
        }

        return $ingredients;
    }

    /**
     * Create each ingredient via the Kaman API.
     *
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
            $progress && $progress('ingredient', 'Creating ingredient '.$i.'/'.$total.': '.($ingredient['name_en'] ?? $key), ['key' => $key]);

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

    private function toSubdomain(string $name): string
    {
        $subdomain = strtolower(trim($name));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '-', $subdomain);
        $subdomain = trim($subdomain, '-');
        $subdomain = preg_replace('/-+/', '-', $subdomain);

        return $subdomain ?: 'default';
    }
}
