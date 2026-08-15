<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CategoryStoreWorkflow extends AbstractFormWorkflow
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
                'error' => 'Description with categories is required.',
            ];
        }

        $subdomain = $this->toSubdomain($restaurantName);
        $baseUrl = KamanUrl::managerApi($subdomain, KamanUrl::tldFromEnvironment($payload['environment'] ?? null));

        try {
            $progress('login', 'Logging in to Kaman API...', ['subdomain' => $subdomain]);
            $loginEmail = KamanUrl::loginEmail($subdomain, $payload['username'] ?? null);
            $token = $this->login($baseUrl, $loginEmail, $password);
            $progress('login', 'Logged in successfully', ['subdomain' => $subdomain]);

            $progress('ai', 'Parsing categories with AI...', []);
            $categories = $this->parseCategoriesWithAi($description);
            $progress('ai', 'Parsed '.count($categories).' categories', ['count' => count($categories)]);

            $progress('categories', 'Creating categories via Kaman API...', []);
            $createResult = $this->createCategories($baseUrl, $token, $categories, $progress);
            $progress('categories', 'Created '.count($createResult['created']).' categories, '.count($createResult['failed']).' failed', $createResult);

            Log::info('CategoryStoreWorkflow completed', [
                'restaurant' => $restaurantName,
                'categories_count' => count($categories),
                'categories_created' => $createResult['created'],
                'categories_failed' => $createResult['failed'],
            ]);

            return [
                'success' => true,
                'message' => 'Category store processed successfully',
                'data' => [
                    'token' => $token,
                    'categories' => $categories,
                    'categories_created' => $createResult['created'],
                    'categories_failed' => $createResult['failed'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('CategoryStoreWorkflow failed', [
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

            Log::warning('CategoryStoreWorkflow login failed', [
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
     * Parse category list from description using AI.
     * Output format: category1: {name_ar, name_en, name_he}, category2: ...
     *
     * @return array<string, array{name_ar: string, name_en: string, name_he: string}>
     */
    private function parseCategoriesWithAi(string $description): array
    {
        $systemPrompt = <<<'PROMPT'
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
            throw new \RuntimeException('AI did not return valid categories. Response: '.substr($aiResponse, 0, 500));
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

        if (! is_array($decoded)) {
            if (preg_match('/\{[\s\S]*"categories"[\s\S]*\}/', $response, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (! is_array($decoded) || ! isset($decoded['categories']) || ! is_array($decoded['categories'])) {
            return [];
        }

        $categories = [];
        $required = ['name_ar', 'name_en', 'name_he'];

        foreach ($decoded['categories'] as $key => $cat) {
            if (! is_array($cat)) {
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

    /**
     * Create each category via the Kaman API.
     *
     * @param  array<string, array{name_ar: string, name_en: string, name_he: string}>  $categories
     * @param  callable(string, string, array): void|null  $progress
     * @return array{created: array<int, array{key: string, id?: mixed}>, failed: array<int, array{key: string, error: string}>}
     */
    private function createCategories(string $baseUrl, string $token, array $categories, ?callable $progress = null): array
    {
        $created = [];
        $failed = [];
        $total = count($categories);
        $i = 0;

        foreach ($categories as $key => $category) {
            $i++;
            $progress && $progress('category', 'Creating category '.$i.'/'.$total.': '.($category['name_en'] ?? $key), ['key' => $key]);

            $response = $this->http()
                ->withToken($token)
                ->post("{$baseUrl}/categories", $category);

            if ($response->successful()) {
                $data = $response->json();
                $created[] = [
                    'key' => $key,
                    'id' => $data['data']['id'] ?? $data['id'] ?? $data['category']['id'] ?? null,
                ];
            } else {
                $body = $response->json();
                $message = $body['message'] ?? $body['error'] ?? $response->body();
                $failed[] = [
                    'key' => $key,
                    'error' => is_string($message) ? $message : json_encode($message),
                ];
                Log::warning('CategoryStoreWorkflow category creation failed', [
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
