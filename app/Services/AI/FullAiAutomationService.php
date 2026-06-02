<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Workflows\AbstractFormWorkflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FullAiAutomationService extends AbstractFormWorkflow
{
    private const CACHE_PREFIX = 'full_ai_session_';

    /**
     * Cache for translated names to avoid repeated AI calls.
     *
     * @var array<string, array{name_en: string, name_ar: string, name_he: string}>
     */
    private array $nameTranslations = [];

    private const MAX_CATEGORY_IMAGES = 5;

    public function start(array $payload, ?string $sessionId = null): array
    {
        set_time_limit(300);
        $sessionId = $sessionId ?? (string) Str::uuid();
        $structure = $this->extractMenuStructure($payload);
        $steps = $this->buildSteps($structure, $payload, $sessionId);

        $state = [
            'payload' => $payload,
            'steps' => $steps,
            'current_index' => 0,
            'diagram' => $this->emptyDiagram(),
        ];

        Cache::put($this->cacheKey($sessionId), $state, now()->addHours(1));

        return [
            'session_id' => $sessionId,
            'next_step' => $steps[0] ?? null,
            'diagram' => $state['diagram'],
        ];
    }

    public function approve(string $sessionId, bool $approved = true): array
    {
        $state = Cache::get($this->cacheKey($sessionId));

        if (!$state) {
            throw new \RuntimeException('This session expired. Please restart the Full AI automation.');
        }

        $index = $state['current_index'] ?? 0;
        $steps = $state['steps'] ?? [];

        if ($index >= count($steps)) {
            return [
                'session_id' => $sessionId,
                'finished' => true,
                'diagram' => $state['diagram'] ?? $this->emptyDiagram(),
                'next_step' => null,
            ];
        }

        $step = $steps[$index];

        if ($approved && isset($step['diagram_fragment'])) {
            $state['diagram'] = $this->mergeDiagram($state['diagram'] ?? $this->emptyDiagram(), $step['diagram_fragment']);
        }

        $state['current_index'] = $index + 1;
        Cache::put($this->cacheKey($sessionId), $state, now()->addHours(1));

        $next = $steps[$state['current_index']] ?? null;

        return [
            'session_id' => $sessionId,
            'finished' => $next === null,
            'diagram' => $state['diagram'],
            'next_step' => $next,
            'applied_step' => $step,
        ];
    }

    /**
     * Lightweight chat assistant for the Full AI flow. Does not send HTTP itself,
     * just helps the user prepare the run and understand what will happen.
     *
     * @param  array<int, array{role:string,content:string}>  $messages
     */
    public function chatAssistant(array $messages): string
    {
        $systemPrompt = <<<PROMPT
You are the Webtimize Full AI automation assistant for the Kaman manager.

Your job:
- Talk with the user in simple language.
- Help them provide: restaurant name, password, menu text or files, optional logo, and any special instructions.
- Explain what the Full AI automation will do: read menus (including Arabic), translate names, generate categories and meals, optionally generate category images from the logo, then prepare HTTP requests to Kaman that the user can approve.

Important:
- YOU do not call Kaman APIs directly. The Laravel backend does that when the user clicks the buttons.
- When you want the user to start the automation, tell them clearly to click the “Start Full AI Automation” button.
- When you want them to approve or skip a step, tell them to use the approval buttons.
- Be concise and concrete. Avoid long paragraphs unless the user asks.
PROMPT;

        $chatMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $m) {
            if (!isset($m['role'], $m['content'])) {
                continue;
            }
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $chatMessages[] = [
                'role' => $role,
                'content' => (string) $m['content'],
            ];
        }

        return $this->chatWithMessages($chatMessages, ['max_tokens' => 600, 'temperature' => 0.5]);
    }

    /**
     * @param  array{categories: array<int, array{name: string, meals: array<int, array{name: string, price?: string, description?: string, ingredients?: array<string>}>}>}  $structure
     */
    private function buildSteps(array $structure, array $payload, string $sessionId): array
    {
        $steps = [];
        $categories = $structure['categories'];

        // Collect all unique names and translate in one batch to avoid timeouts/rate limits
        $namesToTranslate = [];
        foreach ($categories as $index => $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name !== '') {
                $namesToTranslate[$name] = true;
            }
            foreach ($category['meals'] as $meal) {
                $mealName = trim((string) ($meal['name'] ?? ''));
                if ($mealName !== '') {
                    $namesToTranslate[$mealName] = true;
                }
            }
        }
        $this->nameTranslations = $this->translateBatchToLanguages(array_keys($namesToTranslate));

        $categoriesPayload = [];
        foreach ($categories as $index => $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                $name = 'Category ' . ($index + 1);
            }
            $translations = $this->getTranslation($name);
            $categoriesPayload[] = [
                'name_en' => $translations['name_en'],
                'name_ar' => $translations['name_ar'],
                'name_he' => $translations['name_he'],
                'position' => $index + 1,
            ];
        }

        // Generate category images from logo (same style/colors) before storing categories. Capped to avoid timeout.
        $categoryImagePaths = [];
        $logoPath = $payload['logo_path'] ?? null;
        if (!empty($categoriesPayload) && $logoPath !== null && is_string($logoPath) && file_exists($logoPath)) {
            try {
                $stylePrompt = 'Describe this logo in one short sentence: main colors (e.g. orange and white), style (e.g. minimal, vintage, modern), and mood. No other text.';
                $styleDescription = $this->analyzeImage($logoPath, $stylePrompt, ['max_tokens' => 150]);
                $styleDescription = trim($styleDescription) ?: 'clean, professional, appetizing';
                $categoriesDir = public_path('full-ai-sessions/' . $sessionId . '/categories');
                if (!is_dir($categoriesDir)) {
                    mkdir($categoriesDir, 0755, true);
                }
                $generated = 0;
                foreach ($categoriesPayload as $cat) {
                    if ($generated >= self::MAX_CATEGORY_IMAGES) {
                        break;
                    }
                    $nameEn = $cat['name_en'] ?? 'Category';
                    $slug = Str::slug($nameEn) ?: 'category';
                    $savePath = $categoriesDir . '/' . $slug . '.png';
                    $prompt = "Flat vector icon for menu category \"{$nameEn}\". "
                        . "Bold outline, minimal details, no text or letters, no photo texture. "
                        . "Center on a solid background. Match this logo style: {$styleDescription}.";
                    if ($this->generateCategoryImage($prompt, $savePath)) {
                        $categoryImagePaths[$nameEn] = 'full-ai-sessions/' . $sessionId . '/categories/' . $slug . '.png';
                        $generated++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Full AI category image generation failed', ['error' => $e->getMessage()]);
            }
        }

        // Attach image path to categories payload so the proxy can upload it to Kaman
        if (!empty($categoriesPayload) && !empty($categoryImagePaths)) {
            foreach ($categoriesPayload as &$cat) {
                $nameEn = $cat['name_en'] ?? '';
                if ($nameEn !== '' && isset($categoryImagePaths[$nameEn])) {
                    $cat['image_relative_path'] = $categoryImagePaths[$nameEn];
                }
            }
            unset($cat);
        }

        if (!empty($categoriesPayload)) {
            $diagramCategories = array_map(fn ($cat) => [
                'label' => $cat['name_en'],
                'type' => 'category',
                'image_url' => $categoryImagePaths[$cat['name_en'] ?? ''] ?? null,
            ], $categoriesPayload);
            $steps[] = [
                'id' => 'step-categories',
                'title' => 'Create Categories',
                'description' => 'The agent prepared ' . count($categoriesPayload) . ' categories from the uploaded menu.'
                    . (count($categoryImagePaths) > 0 ? ' Generated ' . count($categoryImagePaths) . ' category images from your logo.' : ''),
                'http' => [
                    'method' => 'POST',
                    'url' => '/api/manager/categories',
                    'body' => $categoriesPayload,
                ],
                'diagram_fragment' => [
                    'categories' => $diagramCategories,
                ],
            ];
        }

        $mealsPayload = [];
        foreach ($categories as $category) {
            $categoryName = trim((string) ($category['name'] ?? ''));
            if ($categoryName === '') {
                $categoryName = 'General';
            }
            $categoryTranslations = $this->getTranslation($categoryName);
            foreach ($category['meals'] as $meal) {
                $mealName = trim((string) ($meal['name'] ?? ''));
                if ($mealName === '') {
                    $mealName = 'Item';
                }
                $mealTranslations = $this->getTranslation($mealName);
                $description = trim((string) ($meal['description'] ?? ''));
                $mealsPayload[] = [
                    'name_en' => $mealTranslations['name_en'],
                    'name_ar' => $mealTranslations['name_ar'],
                    'name_he' => $mealTranslations['name_he'],
                    'price' => $meal['price'] ?? '0.00',
                    'category' => $categoryTranslations['name_en'] ?: $categoryName,
                    'description_en' => $description,
                    'description_ar' => $description,
                    'description_he' => $description,
                ];
            }
        }

        if (!empty($mealsPayload)) {
            $steps[] = [
                'id' => 'step-meals',
                'title' => 'Create Meals',
                'description' => 'The agent mapped ' . count($mealsPayload) . ' meals into their categories.',
                'http' => [
                    'method' => 'POST',
                    'url' => '/api/manager/items',
                    'body' => $mealsPayload,
                ],
                'diagram_fragment' => [
                    'meals' => array_map(fn ($meal) => [
                        'label' => $meal['name_en'],
                        'category' => $meal['category'],
                        'price' => $meal['price'],
                    ], $mealsPayload),
                ],
            ];
        }

        $categoryIngredients = [];
        foreach ($categories as $category) {
            $collected = [];
            foreach ($category['meals'] as $meal) {
                foreach ($meal['ingredients'] as $ingredient) {
                    $collected[] = $ingredient;
                }
            }
            $categoryIngredients[] = [
                'category' => $category['name'],
                'ingredients' => array_slice(array_values(array_unique($collected)), 0, 6),
            ];
        }

        if (!empty($categoryIngredients)) {
            $steps[] = [
                'id' => 'step-category-ingredients',
                'title' => 'Category Ingredients',
                'description' => 'Agent generated ingredient buckets for each category.',
                'http' => [
                    'method' => 'POST',
                    'url' => '/api/manager/ingredients-categories',
                    'body' => $categoryIngredients,
                ],
                'diagram_fragment' => [
                    'category_ingredients' => $categoryIngredients,
                ],
            ];
        }

        $allIngredients = [];
        foreach ($categoryIngredients as $entry) {
            foreach ($entry['ingredients'] as $ingredient) {
                $allIngredients[$ingredient] = [
                    'name' => $ingredient,
                    'category' => $entry['category'],
                ];
            }
        }

        if (!empty($allIngredients)) {
            $steps[] = [
                'id' => 'step-ingredients',
                'title' => 'Master Ingredient Library',
                'description' => 'Final pass adds ' . count($allIngredients) . ' ingredients referenced by the menu.',
                'http' => [
                    'method' => 'POST',
                    'url' => '/api/manager/ingredients',
                    'body' => array_values($allIngredients),
                ],
                'diagram_fragment' => [
                    'ingredients' => array_values($allIngredients),
                ],
            ];
        }

        if (empty($steps)) {
            $steps[] = [
                'id' => 'step-placeholder',
                'title' => 'Analyze Menu',
                'description' => 'The agent could not detect categories. It will still send a placeholder summary.',
                'http' => [
                    'method' => 'POST',
                    'url' => '/api/manager/categories',
                    'body' => [
                        ['name_en' => 'General', 'name_ar' => 'قائمة عامة', 'name_he' => 'כללי', 'position' => 1],
                    ],
                ],
                'diagram_fragment' => [
                    'categories' => [
                        ['label' => 'General', 'type' => 'category'],
                    ],
                ],
            ];
        }

        return $steps;
    }

    private function extractMenuStructure(array $payload): array
    {
        $textBlocks = [];
        $rawDescription = trim((string) ($payload['description'] ?? ''));
        if ($rawDescription !== '') {
            $textBlocks[] = $rawDescription;
        }

        $agentInstructions = trim((string) ($payload['agent_instructions'] ?? ''));
        $attachmentSummaries = $this->summarizeAttachments($payload['attachments'] ?? [], $agentInstructions);
        $textBlocks = array_merge($textBlocks, $attachmentSummaries);

        $menuContext = trim(implode("\n\n", $textBlocks));
        if ($menuContext === '') {
            $menuContext = 'No textual menu data provided.';
        }

        $userContext = $agentInstructions !== ''
            ? "\n\nUser instructions (follow these when structuring the menu): " . $agentInstructions
            : '';

        $systemPrompt = <<<PROMPT
You are an autonomous restaurant menu architect. Convert the provided raw menu context (text, OCR snippets, descriptions) into structured JSON with this EXACT schema:
{
  "categories": [
    {
      "name": "Category name",
      "meals": [
        {
          "name": "Meal name",
          "price": "12.00",
          "description": "Short description",
          "ingredients": ["ingredient1", "ingredient2"]
        }
      ]
    }
  ]
}

Requirements:
- Always return valid JSON.
- price must be numeric string; infer if missing.
- description should be concise and meaningful.
- ingredients array should list keywords extracted from the meal name/description. 1-6 items.
- Use category buckets even if not explicitly named (group similar meals).
- Ignore non-food content.
- Do not include markdown.
PROMPT;

        $response = $this->chat($systemPrompt, "Raw menu context:\n{$menuContext}{$userContext}", ['max_tokens' => 4096]);
        $structured = $this->decodeMenuJson($response);

        if (!$structured) {
            // Fallback to minimal structure
            $structured = [
                'categories' => [
                    [
                        'name' => 'General',
                        'meals' => $this->fallbackMealsFromText($menuContext),
                    ],
                ],
            ];
        }

        return $structured;
    }

    private function decodeMenuJson(string $response): ?array
    {
        $response = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['categories']) || !is_array($decoded['categories'])) {
            return null;
        }
        foreach ($decoded['categories'] as &$category) {
            $category['name'] = $category['name'] ?? 'Untitled';
            $category['meals'] = $category['meals'] ?? [];
            foreach ($category['meals'] as &$meal) {
                $meal['name'] = $meal['name'] ?? 'Meal';
                $meal['price'] = isset($meal['price']) ? $this->normalizePrice((string) $meal['price']) : '0.00';
                $meal['description'] = $meal['description'] ?? '';
                $meal['ingredients'] = array_values(array_filter($meal['ingredients'] ?? []));
            }
        }
        return $decoded;
    }

    private function fallbackMealsFromText(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $meals = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) < 4) {
                continue;
            }
            $parts = preg_split('/[:-]/', $line, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }
            $price = isset($parts[1]) ? $this->normalizePrice($parts[1]) : '0.00';
            $meals[] = [
                'name' => $name,
                'price' => $price,
                'description' => 'Auto-generated entry.',
                'ingredients' => $this->guessIngredients($name),
            ];
        }

        if (empty($meals)) {
            $meals[] = [
                'name' => 'Sample Meal',
                'price' => '0.00',
                'description' => 'Placeholder because no menu data was parsed.',
                'ingredients' => [],
            ];
        }

        return $meals;
    }

    /**
     * @param  array<int, array{name?: string, path?: string, relative_path?: string, mime?: string}>  $attachments
     * @return array<int, string>
     */
    private function summarizeAttachments(array $attachments, string $agentInstructions = ''): array
    {
        $summaries = [];
        $userContext = $agentInstructions !== ''
            ? "\n\nUser instructions / context (follow these when reading the menu): " . $agentInstructions
            : '';

        foreach ($attachments as $attachment) {
            $path = $attachment['path'] ?? null;
            $mime = strtolower((string) ($attachment['mime'] ?? ''));
            if (!$path || !file_exists($path)) {
                continue;
            }
            try {
                if (str_starts_with($mime, 'image/')) {
                    $prompt = "This is a restaurant menu image. The text may be written in Arabic or another language.\n"
                        . "Carefully read all visible menu items and prices from the image and return ONLY plain text lines in this format:\n"
                        . "Category > Meal Name > Price [> Optional short description]\n"
                        . "- Keep the original language of names (including Arabic) exactly as written.\n"
                        . "- Normalize prices to plain numbers where possible (e.g. 25, 25.00, 25.5).\n"
                        . "- Do not add any commentary or extra text outside these lines."
                        . $userContext;
                    $result = $this->analyzeImage($path, $prompt, ['max_tokens' => 900]);
                    if ($result) {
                        $summaries[] = "Image ({$attachment['name']}):\n" . trim($result);
                    }
                } elseif ($mime === 'application/pdf') {
                    $imagePath = $this->convertPdfFirstPageToImage($path);
                    if ($imagePath && file_exists($imagePath)) {
                        $prompt = "This is a scanned restaurant menu page, possibly written in Arabic.\n"
                            . "Carefully read all visible menu items and prices and return ONLY plain text lines in this format:\n"
                            . "Category > Meal Name > Price [> Optional short description]\n"
                            . "- Keep the original language of names (including Arabic) exactly as written.\n"
                            . "- Normalize prices to plain numbers where possible (e.g. 25, 25.00, 25.5).\n"
                            . "- Do not add any commentary or extra text outside these lines."
                            . $userContext;
                        $result = $this->analyzeImage($imagePath, $prompt, ['max_tokens' => 900]);
                        if ($result) {
                            $summaries[] = "PDF ({$attachment['name']}):\n" . trim($result);
                        }
                        @unlink($imagePath);
                    } else {
                        $summaries[] = "PDF ({$attachment['name']}): Unable to preview image; treat as text placeholder.";
                    }
                } else {
                    $summaries[] = "Attachment ({$attachment['name']}): unsupported format.";
                }
            } catch (\Throwable $e) {
                $summaries[] = "Attachment ({$attachment['name']}): failed to analyze ({$e->getMessage()}).";
            }
        }
        return $summaries;
    }

    private function convertPdfFirstPageToImage(string $path): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(200, 200);
            $imagick->readImage($path . '[0]');
            $imagick->setImageFormat('png');
            $tempDir = storage_path('app/full-ai-temp');
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/' . Str::uuid() . '.png';
            $imagick->writeImage($tempPath);
            $imagick->clear();
            $imagick->destroy();
            return $tempPath;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function guessIngredients(string $name): array
    {
        $words = preg_split('/[\s\-_,]+/', strtolower($name)) ?: [];
        $ignored = ['with', 'and', 'the', 'meal', 'combo', 'plate', 'classic'];
        $ingredients = [];
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word) ?: '';
            if (strlen($word) < 3) {
                continue;
            }
            if (in_array($word, $ignored, true)) {
                continue;
            }
            $ingredients[] = $word;
        }
        return array_values(array_unique($ingredients));
    }

    private function normalizePrice(string $price): string
    {
        $numeric = (float) preg_replace('/[^0-9.]/', '', $price);
        return number_format($numeric, 2, '.', '');
    }

    /**
     * Translate many menu/category names in one API call. Returns map: lowercase name => [name_en, name_ar, name_he].
     * On failure returns a map that uses the original name for all three languages.
     *
     * @param  array<int, string>  $names
     * @return array<string, array{name_en: string, name_ar: string, name_he: string}>
     */
    private function translateBatchToLanguages(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));
        $result = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if ($key === '') {
                continue;
            }
            $result[$key] = [
                'name_en' => $name,
                'name_ar' => $name,
                'name_he' => $name,
            ];
        }
        if ($names === []) {
            return $result;
        }

        $systemPrompt = <<<PROMPT
You receive a list of restaurant menu terms (category or item names) in any language.
Return a JSON object where each key is exactly one of the given names (copy the name as key) and the value is an object with:
"name_en": "English name",
"name_ar": "Arabic name",
"name_he": "Hebrew name"

Rules:
- Preserve the meaning of each term.
- If a term is already in one target language, use it for that language.
- Keep names short and natural for a menu.
- For all Arabic outputs (`name_ar`), NEVER use Arabic diacritics/tashkeel. Return plain Arabic letters only.
- Output ONLY valid JSON, no markdown. Example: {"Hot Beverages":{"name_en":"Hot Beverages","name_ar":"مشروبات ساخنة","name_he":"משקאות חמים"}}
PROMPT;

        $userPrompt = "Names to translate (one per line):\n" . implode("\n", array_slice($names, 0, 80));

        try {
            $response = $this->chat($systemPrompt, $userPrompt, ['max_tokens' => 4096]);
            $decoded = json_decode(trim($response), true);
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
                $decoded = json_decode(trim($m[1]), true);
            }
            if (is_array($decoded)) {
                foreach ($names as $name) {
                    $key = mb_strtolower($name);
                    $entry = $decoded[$name] ?? $decoded[$key] ?? null;
                    if (is_array($entry)) {
                        $en = trim((string) ($entry['name_en'] ?? $name));
                        $ar = trim((string) ($entry['name_ar'] ?? $en));
                        $he = trim((string) ($entry['name_he'] ?? $en));
                        if ($en !== '' || $ar !== '' || $he !== '') {
                            $result[$key] = [
                                'name_en' => $en ?: $name,
                                'name_ar' => $ar ?: $en,
                                'name_he' => $he ?: $en,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Keep fallback: original name for all three
        }

        return $result;
    }

    /**
     * @return array{name_en: string, name_ar: string, name_he: string}
     */
    private function getTranslation(string $name): array
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return ['name_en' => '', 'name_ar' => '', 'name_he' => ''];
        }
        $default = [
            'name_en' => $name,
            'name_ar' => $name,
            'name_he' => $name,
        ];
        return $this->nameTranslations[$key] ?? $default;
    }

    /**
     * Generate a single category image with DALL-E using the given prompt and save to path.
     */
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
                Log::warning('Category image generation failed', [
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

    /**
     * @param  array<string, array<int, mixed>>  $base
     * @param  array<string, array<int, mixed>>  $fragment
     * @return array<string, array<int, mixed>>
     */
    private function mergeDiagram(array $base, array $fragment): array
    {
        foreach ($fragment as $key => $items) {
            if (!isset($base[$key]) || !is_array($base[$key])) {
                $base[$key] = [];
            }
            foreach ($items as $item) {
                $base[$key][] = $item;
            }
        }
        return $base;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function emptyDiagram(): array
    {
        return [
            'categories' => [],
            'meals' => [],
            'category_ingredients' => [],
            'ingredients' => [],
        ];
    }

    private function cacheKey(string $sessionId): string
    {
        return self::CACHE_PREFIX . $sessionId;
    }

    /**
     * Implementation required by FormWorkflowContract, but Full AI automation does not run through FormWorkflowRunner.
     */
    public function run(array $payload, ?callable $onProgress = null): array
    {
        return [
            'success' => false,
            'error' => 'Direct run() is not supported for FullAiAutomationService. Use start()/approve() endpoints instead.',
        ];
    }
}
