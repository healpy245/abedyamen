<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FormMenuAgentService
{
    public const MAX_TOOL_ITERATIONS = 24;

    public const MAX_BATCH_REQUESTS = 80;

    public const MAX_ATTACHMENTS = 20;

    public function __construct(
        private readonly FormMenuDrinkCatalog $drinks,
    ) {
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{name: string, path: string, mime: string}>  $newAttachments
     * @param  array{attachments?: list<array{name: string, path: string, mime: string}>, menu_draft?: ?array}  $memory
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array}
     */
    public function run(
        array $history,
        array $newAttachments,
        string $token,
        string $baseUrl,
        callable $emit,
        array $memory = [],
    ): array {
        $apiKey = trim((string) (config('openai.api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $attachments = $this->mergeAttachments($memory['attachments'] ?? [], $newAttachments);
        $draft = is_array($memory['menu_draft'] ?? null) ? $memory['menu_draft'] : null;
        $userText = $this->latestUserText($history);
        $hasNewFiles = $newAttachments !== [];

        if ($this->wantsDedupe($userText)) {
            $emit([
                'event' => 'status',
                'message' => 'Finding duplicate categories, meals, and ingredients…',
            ]);
            $wrote = $this->dedupeMenu($token, $baseUrl, $emit);
            $reply = $this->formatDedupeReport($wrote);

            $emit([
                'event' => 'message',
                'reply' => $reply,
            ]);

            return [
                'reply' => $reply,
                'tool_calls' => $wrote['tool_calls'],
                'attachments' => $attachments,
                'menu_draft' => $draft,
            ];
        }

        if ($attachments !== [] && ($hasNewFiles || $draft === null)) {
            $emit([
                'event' => 'status',
                'message' => 'Reading '.count($attachments).' menu file'.(count($attachments) === 1 ? '' : 's').'…',
            ]);
            $extracted = $this->extractMenu($attachments, $userText, $emit);
            if ($extracted !== null) {
                $draft = $extracted;
                $counts = $this->draftCounts($draft);
                $emit([
                    'event' => 'status',
                    'message' => 'Read '.$counts['categories'].' categories, '.$counts['meals'].' meals.',
                ]);
            }
        }

        if ($draft !== null && $this->wantsFullStore($userText, $hasNewFiles)) {
            $emit([
                'event' => 'status',
                'message' => 'Storing every category and meal…',
            ]);
            $wrote = $this->writeFullMenu($draft, $token, $baseUrl, $emit);
            $reply = $this->formatReport($draft, $wrote);

            $emit([
                'event' => 'message',
                'reply' => $reply,
            ]);

            return [
                'reply' => $reply,
                'tool_calls' => $wrote['tool_calls'],
                'attachments' => $attachments,
                'menu_draft' => $draft,
            ];
        }

        $messages = $this->buildMessages($history, $attachments, $baseUrl, $draft);
        $tools = $this->tools();
        $toolCallsLog = [];
        $iterations = 0;

        while ($iterations < self::MAX_TOOL_ITERATIONS) {
            $iterations++;
            $emit([
                'event' => 'status',
                'message' => $iterations === 1 ? 'Thinking…' : 'Working…',
            ]);

            $choice = $this->chatCompletions($apiKey, $messages, $tools, [
                'model' => (string) (config('openai.default_model') ?: 'gpt-4o-mini'),
            ], $emit);
            $toolCalls = $choice['tool_calls'] ?? null;

            if (! is_array($toolCalls) || $toolCalls === []) {
                $reply = trim((string) ($choice['content'] ?? ''));
                if ($reply === '') {
                    $reply = $toolCallsLog === []
                        ? 'I could not produce a reply. Try again with a clearer instruction.'
                        : 'Done. I sent the HTTP requests above.';
                }

                $emit([
                    'event' => 'message',
                    'reply' => $reply,
                ]);

                return [
                    'reply' => $reply,
                    'tool_calls' => $toolCallsLog,
                    'attachments' => $attachments,
                    'menu_draft' => $draft,
                ];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $choice['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $callId = (string) ($call['id'] ?? ('call_'.Str::uuid()));
                $name = (string) ($call['function']['name'] ?? '');
                $arguments = $this->decodeArguments($call['function']['arguments'] ?? '{}');

                $emit([
                    'event' => 'tool_start',
                    'tool' => $name,
                    'arguments' => $this->publicArguments($name, $arguments),
                ]);

                $result = $this->executeTool($name, $arguments, $token, $baseUrl);
                $toolCallsLog[] = [
                    'name' => $name,
                    'arguments' => $this->publicArguments($name, $arguments),
                    'ok' => (bool) ($result['ok'] ?? false),
                    'summary' => (string) ($result['summary'] ?? ''),
                ];

                $emit([
                    'event' => 'tool_result',
                    'tool' => $name,
                    'ok' => (bool) ($result['ok'] ?? false),
                    'summary' => (string) ($result['summary'] ?? ''),
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => (string) ($result['content'] ?? ''),
                ];
            }
        }

        $reply = 'Stopped after too many HTTP rounds. Tell me what is left and I will continue — I still have the photos from this chat.';
        $emit([
            'event' => 'message',
            'reply' => $reply,
        ]);

        return [
            'reply' => $reply,
            'tool_calls' => $toolCallsLog,
            'attachments' => $attachments,
            'menu_draft' => $draft,
        ];
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $existing
     * @param  list<array{name: string, path: string, mime: string}>  $incoming
     * @return list<array{name: string, path: string, mime: string}>
     */
    private function mergeAttachments(array $existing, array $incoming): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge($existing, $incoming) as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '' || ! is_file($path) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $out[] = [
                'name' => (string) ($row['name'] ?? 'file'),
                'path' => $path,
                'mime' => (string) ($row['mime'] ?? ''),
            ];
        }

        return array_slice($out, -self::MAX_ATTACHMENTS);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function latestUserText(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                return trim((string) ($history[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    private function wantsDedupe(string $text): bool
    {
        return preg_match(
            '/delete (the )?duplicates?|remove (the )?duplicates?|dedupe|deduplicate|حذف المكرر|احذف المكرر|امسح المكرر|מחק כפול|תמחק כפול/u',
            mb_strtolower($text)
        ) === 1;
    }

    private function wantsFullStore(string $text, bool $hasNewFiles): bool
    {
        $t = mb_strtolower($text);
        if ($this->wantsDedupe($t)) {
            return false;
        }
        if (preg_match('/fix|update|rename|correct|patch|don.?t mix|לא לערבב|لا تخلط|תקן|صحح/u', $t) === 1) {
            return false;
        }
        if ($hasNewFiles) {
            return true;
        }

        return preg_match('/store|save|create|add all|the rest|remaining|every meal|all meals|خزن|احفظ|أضف كل|כל המנות/u', $t) === 1;
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @return array{categories: list<array<string, mixed>>}|null
     */
    private function extractMenu(array $attachments, string $instructions, callable $emit): ?array
    {
        $images = [];
        $textFiles = [];
        foreach ($attachments as $attachment) {
            $blocks = $this->attachmentContent([$attachment]);
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'image_url') {
                    $images[] = $block;
                } elseif (($block['type'] ?? '') === 'text') {
                    $textFiles[] = (string) ($block['text'] ?? '');
                }
            }
        }

        if ($images === [] && $textFiles === []) {
            return null;
        }

        $chunks = array_chunk($images, 1);
        if ($chunks === []) {
            $chunks = [[]];
        }

        $merged = ['categories' => []];
        foreach ($chunks as $index => $chunk) {
            $emit([
                'event' => 'status',
                'message' => 'OCR '.($index + 1).'/'.count($chunks).'…',
            ]);
            $part = $this->extractMenuChunk($chunk, $textFiles, $instructions, $index + 1, count($chunks), $emit);
            $textFiles = [];
            if ($part !== null) {
                $merged = $this->mergeDrafts($merged, $part);
            }
        }

        return ($merged['categories'] ?? []) === [] ? null : $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $imageBlocks
     * @param  list<string>  $textFiles
     * @return array{categories: list<array<string, mixed>>}|null
     */
    private function extractMenuChunk(array $imageBlocks, array $textFiles, string $instructions, int $part, int $parts, callable $emit): ?array
    {
        $prompt = "You are reading restaurant menu photos (part {$part} of {$parts}).\n"
            ."Extract EVERY visible category and EVERY meal with its price. Do not skip items. Do not summarize.\n"
            ."Keep names in the original language as written. Fill name_en, name_ar, name_he without mixing scripts:\n"
            ."- Arabic fields may contain Arabic letters only (no Hebrew).\n"
            ."- Hebrew fields may contain Hebrew letters only (no Arabic).\n"
            ."description_* should follow the user instructions when given; otherwise a short accurate line.\n"
            ."price is a numeric string like \"80.00\" without currency symbols.\n"
            ."Return ONLY JSON:\n"
            ."{\"categories\":[{\"name_en\":\"\",\"name_ar\":\"\",\"name_he\":\"\",\"meals\":[{\"name_en\":\"\",\"name_ar\":\"\",\"name_he\":\"\",\"price\":\"0.00\",\"description_en\":\"\",\"description_ar\":\"\",\"description_he\":\"\"}]}]}\n";
        if ($instructions !== '') {
            $prompt .= "\nUser instructions (follow exactly):\n{$instructions}\n";
        }
        if ($textFiles !== []) {
            $prompt .= "\n".implode("\n\n", $textFiles)."\n";
        }

        $content = array_merge(
            [['type' => 'text', 'text' => $prompt]],
            $imageBlocks,
        );

        $apiKey = trim((string) (config('openai.api_key') ?? ''));
        $choice = $this->chatCompletions($apiKey, [
            ['role' => 'system', 'content' => 'You extract complete restaurant menus from photos. Output valid JSON only.'],
            ['role' => 'user', 'content' => $content],
        ], null, [
            'max_tokens' => 4096,
            'temperature' => 0.1,
        ], $emit);

        return $this->decodeMenuJson((string) ($choice['content'] ?? ''));
    }

    /**
     * @param  array{categories: list<array<string, mixed>>}  $left
     * @param  array{categories: list<array<string, mixed>>}  $right
     * @return array{categories: list<array<string, mixed>>}
     */
    private function mergeDrafts(array $left, array $right): array
    {
        $byKey = [];
        foreach (array_merge($left['categories'] ?? [], $right['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $key = $this->nameKey((string) ($category['name_ar'] ?? $category['name_en'] ?? $category['name_he'] ?? ''));
            if ($key === '') {
                $key = 'cat-'.count($byKey);
            }
            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'name_en' => (string) ($category['name_en'] ?? ''),
                    'name_ar' => (string) ($category['name_ar'] ?? ''),
                    'name_he' => (string) ($category['name_he'] ?? ''),
                    'meals' => [],
                ];
            }
            $seenMeals = [];
            foreach ($byKey[$key]['meals'] as $meal) {
                $seenMeals[$this->nameKey((string) ($meal['name_ar'] ?? $meal['name_en'] ?? ''))] = true;
            }
            foreach ($category['meals'] ?? [] as $meal) {
                if (! is_array($meal)) {
                    continue;
                }
                $mealKey = $this->nameKey((string) ($meal['name_ar'] ?? $meal['name_en'] ?? $meal['name_he'] ?? ''));
                if ($mealKey !== '' && isset($seenMeals[$mealKey])) {
                    continue;
                }
                $seenMeals[$mealKey] = true;
                $byKey[$key]['meals'][] = [
                    'name_en' => (string) ($meal['name_en'] ?? ''),
                    'name_ar' => (string) ($meal['name_ar'] ?? ''),
                    'name_he' => (string) ($meal['name_he'] ?? ''),
                    'price' => $this->normalizePrice((string) ($meal['price'] ?? '0')),
                    'description_en' => (string) ($meal['description_en'] ?? ''),
                    'description_ar' => (string) ($meal['description_ar'] ?? ''),
                    'description_he' => (string) ($meal['description_he'] ?? ''),
                ];
            }
        }

        return ['categories' => array_values($byKey)];
    }

    /**
     * @param  array{categories: list<array<string, mixed>>}  $draft
     * @return array{tool_calls: list<array<string, mixed>>, categories_created: int, categories_skipped: int, meals_created: int, meals_skipped: int, ingredients_created: int, ingredients_skipped: int, failed: list<string>}
     */
    private function writeFullMenu(array $draft, string $token, string $baseUrl, callable $emit): array
    {
        $toolCalls = [];
        $failed = [];
        $categories = $this->fetchList($token, $baseUrl, '/categories');
        $categoryIds = $this->indexByName($categories);
        $createdCategories = 0;
        $skippedCategories = 0;
        $position = max(1, count($categories) + 1);

        foreach ($draft['categories'] as $index => $category) {
            $names = $this->rowNames($category);
            $existingId = $this->firstMappedId($categoryIds, $names);
            if ($existingId !== null) {
                $skippedCategories++;
                continue;
            }

            $body = [
                'name_en' => $names[0] !== '' ? $names[0] : ($names[1] !== '' ? $names[1] : 'Category'),
                'name_ar' => $names[1] !== '' ? $names[1] : $names[0],
                'name_he' => $names[2] !== '' ? $names[2] : $names[0],
                'position' => $position + $index,
            ];
            $sent = $this->sendTracked($emit, $toolCalls, 'POST', '/categories', $body, $token, $baseUrl);
            if ($sent['ok']) {
                $createdCategories++;
                $newId = $this->extractId($sent['content']);
                if ($newId !== null) {
                    $this->rememberNames($categoryIds, $names, $newId);
                }
                continue;
            }
            if ($this->looksLikeDuplicate($sent)) {
                $skippedCategories++;
                $refreshed = $this->indexByName($this->fetchList($token, $baseUrl, '/categories'));
                $categoryIds = array_merge($categoryIds, $refreshed);
                continue;
            }
            $failed[] = 'Category '.$body['name_en'].' (HTTP '.$sent['status'].')';
        }

        if ($createdCategories > 0) {
            $refreshed = $this->indexByName($this->fetchList($token, $baseUrl, '/categories'));
            if ($refreshed !== []) {
                $categoryIds = array_merge($categoryIds, $refreshed);
            }
        }

        $itemKeys = $this->indexItems($this->fetchList($token, $baseUrl, '/items'));
        $createdMeals = 0;
        $skippedMeals = 0;
        foreach ($draft['categories'] as $category) {
            $names = $this->rowNames($category);
            $categoryId = $this->firstMappedId($categoryIds, $names);
            if ($categoryId === null) {
                $failed[] = 'No category id for '.($names[0] ?: $names[1]);
                continue;
            }

            foreach ($category['meals'] ?? [] as $meal) {
                if (! is_array($meal)) {
                    continue;
                }
                $mealNames = $this->rowNames($meal);
                if ($this->itemExists($itemKeys, $categoryId, $mealNames)) {
                    $skippedMeals++;
                    continue;
                }

                $body = [
                    'name_en' => $mealNames[0] !== '' ? $mealNames[0] : $mealNames[1],
                    'name_ar' => $mealNames[1] !== '' ? $mealNames[1] : $mealNames[0],
                    'name_he' => $mealNames[2] !== '' ? $mealNames[2] : $mealNames[0],
                    'price' => $this->normalizePrice((string) ($meal['price'] ?? '0')),
                    'category_id' => $categoryId,
                    'description_en' => (string) ($meal['description_en'] ?? ''),
                    'description_ar' => (string) ($meal['description_ar'] ?? ''),
                    'description_he' => (string) ($meal['description_he'] ?? ''),
                ];
                $image = $this->drinks->imageFor($mealNames);
                $sent = $this->sendKaman('POST', '/items', $body, $token, $baseUrl, $image);
                $this->trackHttp($emit, $toolCalls, 'POST', '/items', $sent, (bool) ($sent['with_image'] ?? false));
                if ($sent['ok'] || $this->looksLikeDuplicate($sent)) {
                    if ($sent['ok']) {
                        $createdMeals++;
                    } else {
                        $skippedMeals++;
                    }
                    $this->rememberItem($itemKeys, $categoryId, $mealNames, $this->extractId($sent['content']) ?? '1');
                    continue;
                }
                $failed[] = $body['name_en'].' (HTTP '.$sent['status'].')';
            }
        }

        $ingredientIds = $this->indexByName($this->fetchIngredients($token, $baseUrl));
        $createdIngredients = 0;
        $skippedIngredients = 0;
        foreach ($draft['categories'] as $category) {
            foreach ($category['ingredients'] ?? [] as $ingredient) {
                if (! is_array($ingredient)) {
                    continue;
                }
                $names = $this->rowNames($ingredient);
                if ($this->firstMappedId($ingredientIds, $names) !== null) {
                    $skippedIngredients++;
                    continue;
                }
                $body = [
                    'name_en' => $names[0] !== '' ? $names[0] : $names[1],
                    'name_ar' => $names[1] !== '' ? $names[1] : $names[0],
                    'name_he' => $names[2] !== '' ? $names[2] : $names[0],
                ];
                $sent = $this->sendTracked($emit, $toolCalls, 'POST', '/ingredients', $body, $token, $baseUrl);
                if ($sent['ok']) {
                    $createdIngredients++;
                    $newId = $this->extractId($sent['content']);
                    if ($newId !== null) {
                        $this->rememberNames($ingredientIds, $names, $newId);
                    }
                    continue;
                }
                if ($this->looksLikeDuplicate($sent)) {
                    $skippedIngredients++;
                    continue;
                }
                $failed[] = 'Ingredient '.$body['name_en'].' (HTTP '.$sent['status'].')';
            }
        }

        $summary = $createdMeals.' meals stored, '.$skippedMeals.' already existed'
            .($skippedCategories > 0 ? ', '.$skippedCategories.' categories already existed' : '')
            .($createdIngredients > 0 || $skippedIngredients > 0
                ? ', '.$createdIngredients.' ingredients stored, '.$skippedIngredients.' already existed'
                : '')
            .($failed === [] ? '' : ', '.count($failed).' failed');
        $emit([
            'event' => 'tool_result',
            'tool' => 'kaman_request_many',
            'ok' => $failed === [],
            'summary' => $summary,
        ]);
        $toolCalls[] = [
            'name' => 'kaman_request_many',
            'arguments' => ['count' => $createdMeals],
            'ok' => $failed === [],
            'summary' => $summary,
        ];

        return [
            'tool_calls' => $toolCalls,
            'categories_created' => $createdCategories,
            'categories_skipped' => $skippedCategories,
            'meals_created' => $createdMeals,
            'meals_skipped' => $skippedMeals,
            'ingredients_created' => $createdIngredients,
            'ingredients_skipped' => $skippedIngredients,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array{categories: list<array<string, mixed>>}  $draft
     * @param  array{categories_created: int, categories_skipped?: int, meals_created: int, meals_skipped: int, ingredients_created?: int, ingredients_skipped?: int, failed: list<string>}  $wrote
     */
    private function formatReport(array $draft, array $wrote): string
    {
        $counts = $this->draftCounts($draft);
        $skippedCategories = (int) ($wrote['categories_skipped'] ?? 0);
        $ingredientCreated = (int) ($wrote['ingredients_created'] ?? 0);
        $ingredientSkipped = (int) ($wrote['ingredients_skipped'] ?? 0);
        $lines = [
            'Stored the full menu from the photos in this chat.',
            $counts['categories'].' categories, '.$counts['meals'].' meals read.',
            $wrote['categories_created'].' categories created'
                .($skippedCategories > 0 ? ', '.$skippedCategories.' already on the dashboard' : '')
                .', '.$wrote['meals_created'].' meals created'
                .($wrote['meals_skipped'] > 0 ? ', '.$wrote['meals_skipped'].' already on the dashboard' : '').'.',
        ];
        if ($ingredientCreated > 0 || $ingredientSkipped > 0) {
            $lines[] = $ingredientCreated.' ingredients created'
                .($ingredientSkipped > 0 ? ', '.$ingredientSkipped.' already on the dashboard' : '').'.';
        }
        $lines[] = '';
        foreach ($draft['categories'] as $category) {
            $label = trim((string) ($category['name_ar'] ?? '')).' / '.trim((string) ($category['name_en'] ?? ''));
            $label = trim($label, ' /');
            $meals = is_array($category['meals'] ?? null) ? $category['meals'] : [];
            $lines[] = $label.' ('.count($meals).')';
            foreach ($meals as $meal) {
                if (! is_array($meal)) {
                    continue;
                }
                $nameAr = trim((string) ($meal['name_ar'] ?? ''));
                $nameEn = trim((string) ($meal['name_en'] ?? ''));
                $name = $nameAr !== '' && $nameEn !== '' && $nameAr !== $nameEn
                    ? $nameAr.' / '.$nameEn
                    : ($nameAr !== '' ? $nameAr : $nameEn);
                $price = $this->normalizePrice((string) ($meal['price'] ?? '0'));
                $lines[] = '  • '.$name.' — '.$price;
            }
            $lines[] = '';
        }
        if ($wrote['failed'] !== []) {
            $lines[] = 'Failed:';
            foreach ($wrote['failed'] as $row) {
                $lines[] = '  • '.$row;
            }
        }
        $lines[] = 'I still have these photos in this chat. You can say “fix …” or “delete the duplicates” without uploading again.';

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array{categories?: list<array<string, mixed>>}  $draft
     * @return array{categories: int, meals: int}
     */
    private function draftCounts(array $draft): array
    {
        $categories = $draft['categories'] ?? [];
        $meals = 0;
        foreach ($categories as $category) {
            $meals += is_array($category['meals'] ?? null) ? count($category['meals']) : 0;
        }

        return [
            'categories' => count($categories),
            'meals' => $meals,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $token, string $baseUrl, string $path): array
    {
        $sent = $this->sendKaman('GET', $path, [], $token, $baseUrl);
        if (! $sent['ok']) {
            return [];
        }
        $payload = json_decode($sent['content'], true);
        if (! is_array($payload)) {
            return [];
        }
        $list = $payload['data']
            ?? $payload['categories']
            ?? $payload['items']
            ?? $payload['ingredients']
            ?? $payload['ingredients_categories']
            ?? $payload;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchIngredients(string $token, string $baseUrl): array
    {
        return $this->fetchList($token, $baseUrl, '/ingredients');
    }

    /**
     * @return array{tool_calls: list<array<string, mixed>>, deleted: array<string, int>, kept: array<string, int>, failed: list<string>}
     */
    private function dedupeMenu(string $token, string $baseUrl, callable $emit): array
    {
        $toolCalls = [];
        $failed = [];
        $deleted = ['categories' => 0, 'meals' => 0, 'ingredients' => 0];
        $kept = ['categories' => 0, 'meals' => 0, 'ingredients' => 0];

        $itemResult = $this->dedupeRows(
            $this->fetchList($token, $baseUrl, '/items'),
            '/items',
            $token,
            $baseUrl,
            $emit,
            $toolCalls,
            $failed
        );
        $deleted['meals'] = $itemResult['deleted'];
        $kept['meals'] = $itemResult['kept'];

        $ingredientResult = $this->dedupeRows(
            $this->fetchIngredients($token, $baseUrl),
            '/ingredients',
            $token,
            $baseUrl,
            $emit,
            $toolCalls,
            $failed
        );
        $deleted['ingredients'] = $ingredientResult['deleted'];
        $kept['ingredients'] = $ingredientResult['kept'];

        $categoryResult = $this->dedupeRows(
            $this->fetchList($token, $baseUrl, '/categories'),
            '/categories',
            $token,
            $baseUrl,
            $emit,
            $toolCalls,
            $failed
        );
        $deleted['categories'] = $categoryResult['deleted'];
        $kept['categories'] = $categoryResult['kept'];

        $summary = 'Deleted '.$deleted['meals'].' duplicate meals, '.$deleted['categories'].' duplicate categories, '.$deleted['ingredients'].' duplicate ingredients';
        $emit([
            'event' => 'tool_result',
            'tool' => 'kaman_request_many',
            'ok' => $failed === [],
            'summary' => $summary,
        ]);

        return [
            'tool_calls' => $toolCalls,
            'deleted' => $deleted,
            'kept' => $kept,
            'failed' => $failed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<string>  $failed
     * @return array{deleted: int, kept: int}
     */
    private function dedupeRows(
        array $rows,
        string $pathPrefix,
        string $token,
        string $baseUrl,
        callable $emit,
        array &$toolCalls,
        array &$failed,
    ): array {
        $groups = $this->groupDuplicates($rows);
        $deleted = 0;
        $kept = 0;
        foreach ($groups as $group) {
            if ($group === []) {
                continue;
            }
            usort($group, function (array $a, array $b): int {
                $idA = $this->rowId($a);
                $idB = $this->rowId($b);

                return ((int) $idA) <=> ((int) $idB) ?: strcmp($idA, $idB);
            });
            $keep = array_shift($group);
            if ($keep !== null) {
                $kept++;
            }
            foreach ($group as $extra) {
                $id = $this->rowId($extra);
                if ($id === '') {
                    continue;
                }
                $label = $this->rowLabel($extra);
                $sent = $this->sendTracked($emit, $toolCalls, 'DELETE', $pathPrefix.'/'.$id, [], $token, $baseUrl);
                if ($sent['ok'] || $sent['status'] === 404) {
                    $deleted++;
                    continue;
                }
                $failed[] = $label.' (HTTP '.$sent['status'].')';
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function groupDuplicates(array $rows): array
    {
        $keyToGroup = [];
        $groups = [];
        foreach ($rows as $row) {
            if (! is_array($row) || $this->rowId($row) === '') {
                continue;
            }
            $keys = [];
            foreach ($this->rowNames($row) as $name) {
                $key = $this->nameKey($name);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
            if ($keys === []) {
                $groups[] = [$row];
                continue;
            }
            $match = null;
            foreach ($keys as $key) {
                if (isset($keyToGroup[$key])) {
                    $match = $keyToGroup[$key];
                    break;
                }
            }
            if ($match === null) {
                $match = count($groups);
                $groups[$match] = [];
            }
            $groups[$match][] = $row;
            foreach ($keys as $key) {
                $keyToGroup[$key] = $match;
            }
        }

        return array_values($groups);
    }

    /**
     * @param  array{deleted: array<string, int>, kept: array<string, int>, failed: list<string>}  $wrote
     */
    private function formatDedupeReport(array $wrote): string
    {
        $lines = [
            'Removed duplicate menu rows. Kept the oldest of each name (lowest id).',
            'Meals: deleted '.$wrote['deleted']['meals'].', kept '.$wrote['kept']['meals'].'.',
            'Categories: deleted '.$wrote['deleted']['categories'].', kept '.$wrote['kept']['categories'].'.',
            'Ingredients: deleted '.$wrote['deleted']['ingredients'].', kept '.$wrote['kept']['ingredients'].'.',
        ];
        if ($wrote['failed'] !== []) {
            $lines[] = 'Failed:';
            foreach ($wrote['failed'] as $row) {
                $lines[] = '  • '.$row;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function indexByName(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['category_id'] ?? null;
            if ($id === null) {
                continue;
            }
            foreach (['name_en', 'name_ar', 'name_he', 'name'] as $field) {
                $key = $this->nameKey((string) ($row[$field] ?? ''));
                if ($key !== '') {
                    $map[$key] = (string) $id;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $names
     */
    private function firstMappedId(array $map, array $names): ?string
    {
        foreach ($names as $name) {
            $key = $this->nameKey($name);
            if ($key !== '' && isset($map[$key])) {
                return $map[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowNames(array $row): array
    {
        return [
            (string) ($row['name_en'] ?? ''),
            (string) ($row['name_ar'] ?? ''),
            (string) ($row['name_he'] ?? ''),
            (string) ($row['name'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowId(array $row): string
    {
        $id = $row['id'] ?? $row['category_id'] ?? $row['item_id'] ?? $row['ingredient_id'] ?? null;

        return $id === null ? '' : (string) $id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowLabel(array $row): string
    {
        foreach ($this->rowNames($row) as $name) {
            if (trim($name) !== '') {
                return trim($name);
            }
        }

        return '#'.$this->rowId($row);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function indexItems(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $id = $this->rowId($row);
            if ($id === '') {
                continue;
            }
            $catId = (string) ($row['category_id'] ?? $row['category']['id'] ?? '');
            $this->rememberItem($map, $catId, $this->rowNames($row), $id);
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $names
     */
    private function itemExists(array $map, string $categoryId, array $names): bool
    {
        foreach ($names as $name) {
            $key = $this->nameKey($name);
            if ($key === '') {
                continue;
            }
            if (isset($map['*:'.$key]) || ($categoryId !== '' && isset($map[$categoryId.':'.$key]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $names
     */
    private function rememberItem(array &$map, string $categoryId, array $names, string $id): void
    {
        foreach ($names as $name) {
            $key = $this->nameKey($name);
            if ($key === '') {
                continue;
            }
            $map['*:'.$key] = $id;
            if ($categoryId !== '') {
                $map[$categoryId.':'.$key] = $id;
            }
        }
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $names
     */
    private function rememberNames(array &$map, array $names, string $id): void
    {
        foreach ($names as $name) {
            $key = $this->nameKey($name);
            if ($key !== '') {
                $map[$key] = $id;
            }
        }
    }

    /**
     * @param  array{ok: bool, status: int, content: string}  $sent
     */
    private function looksLikeDuplicate(array $sent): bool
    {
        if ($sent['status'] === 409) {
            return true;
        }
        $content = mb_strtolower($sent['content']);

        return preg_match('/already|exist|duplicate|taken|unique|קיים|موجود|مكرر/u', $content) === 1;
    }

    private function extractId(string $content): ?string
    {
        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            return null;
        }
        $id = $payload['data']['id'] ?? $payload['id'] ?? $payload['category']['id'] ?? $payload['item']['id'] ?? $payload['ingredient']['id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, content: string}
     */
    private function sendTracked(
        callable $emit,
        array &$toolCalls,
        string $method,
        string $path,
        array $body,
        string $token,
        string $baseUrl,
        ?string $imagePath = null,
    ): array {
        $sent = $this->sendKaman($method, $path, $body, $token, $baseUrl, $imagePath);
        $this->trackHttp($emit, $toolCalls, $method, $path, $sent, (bool) ($sent['with_image'] ?? false));

        return $sent;
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  array{ok: bool, status: int, content: string}  $sent
     */
    private function trackHttp(
        callable $emit,
        array &$toolCalls,
        string $method,
        string $path,
        array $sent,
        bool $withImage = false,
    ): void {
        $summary = $method.' '.$path.' → HTTP '.$sent['status']
            .($withImage ? ' (image)' : '')
            .($this->looksLikeDuplicate($sent) && ! $sent['ok'] ? ' skipped duplicate' : '');
        $toolCalls[] = [
            'name' => 'kaman_request',
            'arguments' => ['method' => $method, 'path' => $path],
            'ok' => $sent['ok'] || $this->looksLikeDuplicate($sent),
            'summary' => $summary,
        ];
        $emit([
            'event' => 'tool_start',
            'tool' => 'kaman_request',
            'arguments' => ['method' => $method, 'path' => $path],
        ]);
        $emit([
            'event' => 'tool_result',
            'tool' => 'kaman_request',
            'ok' => $sent['ok'] || $this->looksLikeDuplicate($sent),
            'summary' => $summary,
        ]);
    }

    private function nameKey(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = strtr($name, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);
        $name = preg_replace('/^ال/u', '', $name) ?? $name;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name;
    }

    private function normalizePrice(string $price): string
    {
        $numeric = preg_replace('/[^0-9.]/', '', $price) ?? '0';

        return number_format((float) $numeric, 2, '.', '');
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @param  array{categories: list<array<string, mixed>>}|null  $draft
     * @return list<array<string, mixed>>
     */
    private function buildMessages(array $history, array $attachments, string $baseUrl, ?array $draft): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($baseUrl, $attachments, $draft)],
        ];

        $lastUserIndex = null;
        foreach ($history as $row) {
            $role = ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '' && $role !== 'user') {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
            if ($role === 'user') {
                $lastUserIndex = count($messages) - 1;
            }
        }

        if ($lastUserIndex === null) {
            $messages[] = [
                'role' => 'user',
                'content' => $attachments === [] ? '(empty)' : 'Use the menu photos already in this chat.',
            ];
            $lastUserIndex = count($messages) - 1;
        }

        $extra = [];
        if ($draft !== null) {
            $extra[] = [
                'type' => 'text',
                'text' => "EXTRACTED MENU ALREADY IN THIS CHAT (do not ask to re-upload):\n"
                    .$this->truncate(json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 20000),
            ];
        }

        $vision = $draft !== null ? [] : array_slice($this->attachmentContent($attachments), 0, 1);
        $text = trim((string) $messages[$lastUserIndex]['content']);
        if ($text === '') {
            $text = 'Continue from the photos and menu already in this chat.';
        }
        if ($extra !== [] || $vision !== []) {
            $messages[$lastUserIndex]['content'] = array_merge(
                [['type' => 'text', 'text' => $text]],
                $extra,
                $vision,
            );
        }

        return $messages;
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @return list<array<string, mixed>>
     */
    private function attachmentContent(array $attachments): array
    {
        $blocks = [];
        $imageCount = 0;

        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $name = (string) ($attachment['name'] ?? 'file');
            $mime = strtolower((string) ($attachment['mime'] ?? ''));
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            if (str_contains($mime, 'json') || str_ends_with(strtolower($name), '.json')
                || str_starts_with($mime, 'text/') || str_ends_with(strtolower($name), '.txt')
                || str_ends_with(strtolower($name), '.csv')) {
                $raw = (string) file_get_contents($path);
                $blocks[] = [
                    'type' => 'text',
                    'text' => "File {$name}:\n".$this->truncate($raw, 60000),
                ];
                continue;
            }

            $imagePath = $path;
            if ($mime === 'application/pdf' || str_ends_with(strtolower($name), '.pdf')) {
                $converted = $this->pdfFirstPageToImage($path);
                if ($converted === null) {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => "PDF {$name} could not be previewed.",
                    ];
                    continue;
                }
                $imagePath = $converted;
                $mime = 'image/png';
            }

            if (! str_starts_with($mime, 'image/') && ! $this->looksLikeImage($imagePath)) {
                continue;
            }

            $imageCount++;
            if ($imageCount > 15) {
                continue;
            }

            $blocks[] = $this->toVisionInput($this->prepareVisionImage($imagePath));
        }

        return $blocks;
    }

    private function looksLikeImage(string $path): bool
    {
        $mime = strtolower((string) (mime_content_type($path) ?: ''));

        return str_starts_with($mime, 'image/');
    }

    private function prepareVisionImage(string $path): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return $path;
        }
        $info = @getimagesize($path);
        if (! is_array($info)) {
            return $path;
        }
        [$width, $height, $type] = $info;
        $max = 1024;
        $bytes = (int) (@filesize($path) ?: 0);
        if ($width <= $max && $height <= $max && $bytes > 0 && $bytes < 400_000) {
            return $path;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($src === false) {
            return $path;
        }

        $scale = min($max / max(1, $width), $max / max(1, $height), 1);
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);

            return $path;
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        $temp = storage_path('app/menu-agent-temp/'.Str::uuid().'.jpg');
        if (! is_dir(dirname($temp))) {
            mkdir(dirname($temp), 0755, true);
        }
        imagejpeg($dst, $temp, 72);
        imagedestroy($src);
        imagedestroy($dst);

        return is_file($temp) ? $temp : $path;
    }

    /**
     * @return array{type: string, image_url: array{url: string, detail: string}}
     */
    private function toVisionInput(string $imagePath): array
    {
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $data = base64_encode((string) file_get_contents($imagePath));

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$mime.';base64,'.$data,
                'detail' => 'high',
            ],
        ];
    }

    private function pdfFirstPageToImage(string $path): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $imagick = new \Imagick;
            $imagick->setResolution(200, 200);
            $imagick->readImage($path.'[0]');
            $imagick->setImageFormat('png');
            $tempDir = storage_path('app/menu-agent-temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir.'/'.Str::uuid().'.png';
            $imagick->writeImage($tempPath);
            $imagick->clear();
            $imagick->destroy();

            return $tempPath;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @param  array{categories: list<array<string, mixed>>}|null  $draft
     */
    private function systemPrompt(string $baseUrl, array $attachments, ?array $draft): string
    {
        $host = KamanUrl::originFromManagerApi($baseUrl);
        $fileNames = array_map(static fn (array $file): string => (string) $file['name'], $attachments);
        $fileLine = $fileNames === []
            ? 'No files in this chat yet.'
            : 'You ALREADY have '.count($fileNames).' files in this chat: '.implode(', ', $fileNames).'. NEVER say you lack access. NEVER ask to re-upload.';
        $draftLine = $draft === null
            ? 'No extracted menu draft yet.'
            : 'A full extracted menu JSON is attached to the latest user message. Use it. Store remaining meals if any are missing.';

        return <<<PROMPT
You are the restaurant menu operator on the Kaman Form page. Talk and act like Cursor: read the files, then send HTTP yourself. Hands-free. No WhatsApp.

Logged in:
- Base: {$baseUrl}
- Dashboard: {$host}

{$fileLine}
{$draftLine}

Use tools to patch, rename, create leftovers, or delete duplicates. Do not stop after a sample. If the job is to store a menu, every meal from every photo must be written — except names that already exist.

Tools:
- kaman_request: one HTTP call (GET, POST, PUT, PATCH, DELETE)
- kaman_request_many: many HTTP calls in order (bulk meals). Max 80 per call; call again for the rest.

Paths are relative to /api/manager:
- GET /categories
- POST /categories  {name_en, name_ar, name_he, position}
- DELETE /categories/{id}
- GET /items
- POST /items  {name_en, name_ar, name_he, price, category_id, description_ar, description_en, description_he}
- PUT /items/{id} or PATCH /items/{id}
- DELETE /items/{id}
- GET /ingredients
- POST /ingredients  {name_en, name_ar, name_he}
- DELETE /ingredients/{id}

Rules:
- Follow user instructions exactly (item name as description, dialect spellings, etc.).
- Never mix Hebrew letters into Arabic, or Arabic letters into Hebrew.
- Keep original menu language; still fill name_en, name_ar, name_he accurately.
- price is a numeric string like "25.00".
- Before creating, GET existing rows. Skip any category, meal, or ingredient whose name already exists (any language). Do not POST duplicates.
- If the user asks to delete duplicates, GET the list, group by name, keep the lowest id, DELETE the extras.
- Known drinks (cola, sprite, water, pepsi, fanta, …) are stored with catalog images automatically on POST /items.
- After writes, list counts per category, not a partial sample.
- If something is ambiguous, pick the smallest reasonable choice and mention it.
PROMPT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $requestSchema = [
            'type' => 'object',
            'properties' => [
                'method' => [
                    'type' => 'string',
                    'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Path under /api/manager, e.g. /items or /items/12',
                ],
                'body' => [
                    'type' => 'object',
                    'description' => 'JSON body for POST/PUT/PATCH. Omit for GET/DELETE.',
                ],
            ],
            'required' => ['method', 'path'],
        ];

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'kaman_request',
                    'description' => 'Send one HTTP request to this restaurant\'s Kaman manager API.',
                    'parameters' => $requestSchema,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'kaman_request_many',
                    'description' => 'Send many HTTP requests in order to the Kaman manager API. Use for bulk meal/category creates. Max 80.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'requests' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => self::MAX_BATCH_REQUESTS,
                                'items' => $requestSchema,
                            ],
                        ],
                        'required' => ['requests'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function executeTool(string $name, array $arguments, string $token, string $baseUrl): array
    {
        return match ($name) {
            'kaman_request' => $this->kamanRequest($arguments, $token, $baseUrl),
            'kaman_request_many' => $this->kamanRequestMany($arguments, $token, $baseUrl),
            default => [
                'ok' => false,
                'summary' => 'Unknown tool '.$name,
                'content' => json_encode(['error' => 'Unknown tool'], JSON_UNESCAPED_UNICODE),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function kamanRequest(array $arguments, string $token, string $baseUrl): array
    {
        $method = strtoupper(trim((string) ($arguments['method'] ?? 'GET')));
        $path = (string) ($arguments['path'] ?? '');
        $body = $arguments['body'] ?? [];
        if (! is_array($body)) {
            $body = [];
        }

        $result = $this->sendKaman($method, $path, $body, $token, $baseUrl);
        $summary = $method.' '.$this->normalizePath($path).' → HTTP '.$result['status']
            .(! empty($result['with_image']) ? ' (image)' : '');
        if (! $result['ok']) {
            $summary .= ' failed';
        }

        return [
            'ok' => $result['ok'],
            'summary' => $summary,
            'content' => $this->truncate((string) $result['content'], 12000),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function kamanRequestMany(array $arguments, string $token, string $baseUrl): array
    {
        $requests = $arguments['requests'] ?? [];
        if (! is_array($requests) || $requests === []) {
            return [
                'ok' => false,
                'summary' => 'No requests given',
                'content' => json_encode(['error' => 'requests array is required'], JSON_UNESCAPED_UNICODE),
            ];
        }

        $requests = array_slice(array_values($requests), 0, self::MAX_BATCH_REQUESTS);
        $results = [];
        $okCount = 0;
        $failCount = 0;

        foreach ($requests as $index => $row) {
            if (! is_array($row)) {
                $failCount++;
                $results[] = ['index' => $index, 'ok' => false, 'error' => 'Invalid request'];
                continue;
            }

            $method = strtoupper(trim((string) ($row['method'] ?? 'GET')));
            $path = (string) ($row['path'] ?? '');
            $body = is_array($row['body'] ?? null) ? $row['body'] : [];
            $sent = $this->sendKaman($method, $path, $body, $token, $baseUrl);
            if ($sent['ok']) {
                $okCount++;
            } else {
                $failCount++;
            }
            $results[] = [
                'index' => $index,
                'ok' => $sent['ok'],
                'method' => $method,
                'path' => $this->normalizePath($path),
                'status' => $sent['status'],
                'body' => $this->decodeMaybeJson($sent['content']),
            ];
        }

        return [
            'ok' => $failCount === 0,
            'summary' => $okCount.' ok, '.$failCount.' failed',
            'content' => $this->truncate(json_encode([
                'ok' => $okCount,
                'failed' => $failCount,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 20000),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, content: string, with_image: bool}
     */
    private function sendKaman(string $method, string $path, array $body, string $token, string $baseUrl, ?string $imagePath = null): array
    {
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
        if (! in_array($method, $allowed, true)) {
            return [
                'ok' => false,
                'status' => 0,
                'content' => json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE),
                'with_image' => false,
            ];
        }

        $normalized = $this->normalizePath($path);
        if ($normalized === null) {
            return [
                'ok' => false,
                'status' => 0,
                'content' => json_encode(['error' => 'Invalid path. Use a manager path like /items.'], JSON_UNESCAPED_UNICODE),
                'with_image' => false,
            ];
        }

        if ($imagePath === null
            && in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && preg_match('#^/items(?:/\d+)?$#', $normalized) === 1
        ) {
            $imagePath = $this->drinks->imageFor([
                (string) ($body['name_en'] ?? ''),
                (string) ($body['name_ar'] ?? ''),
                (string) ($body['name_he'] ?? ''),
                (string) ($body['name'] ?? ''),
            ]);
        }

        $url = KamanUrl::join($baseUrl, $normalized);
        $canAttachImage = $imagePath !== null
            && is_file($imagePath)
            && in_array($method, ['POST', 'PUT', 'PATCH'], true);

        $sent = $this->dispatchKaman(
            $method,
            $url,
            $token,
            $canAttachImage ? $this->prepareMultipartItemBody($body) : $body,
            $canAttachImage ? $imagePath : null,
        );

        if ($canAttachImage && ! $sent['ok'] && ! $this->looksLikeDuplicate($sent)) {
            Log::warning('Menu agent item image rejected; retrying without image', [
                'url' => $url,
                'status' => $sent['status'],
                'image' => basename((string) $imagePath),
                'response' => mb_substr($sent['content'], 0, 500),
            ]);
            $retry = $this->dispatchKaman($method, $url, $token, $body, null);
            if ($retry['ok'] || $this->looksLikeDuplicate($retry)) {
                return $retry;
            }

            return [
                'ok' => false,
                'status' => $sent['status'] ?: $retry['status'],
                'content' => $sent['content'] !== '' ? $sent['content'] : $retry['content'],
                'with_image' => true,
            ];
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, content: string, with_image: bool}
     */
    private function dispatchKaman(string $method, string $url, string $token, array $body, ?string $imagePath): array
    {
        $http = Http::timeout(60)->acceptJson()->withToken($token);
        if (! config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            if ($imagePath !== null) {
                $filename = basename($imagePath);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    default => 'application/octet-stream',
                };
                $http = $http->attach('image', (string) file_get_contents($imagePath), $filename, [
                    'Content-Type' => $mime,
                ]);
                $response = $http->{strtolower($method)}($url, $body);
            } elseif (in_array($method, ['GET', 'HEAD'], true)) {
                $response = $http->{strtolower($method)}($url);
            } elseif ($method === 'DELETE' && $body === []) {
                $response = $http->delete($url);
            } else {
                $response = $http->{strtolower($method)}($url, $body);
            }
        } catch (\Throwable $e) {
            Log::warning('Menu agent Kaman request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'content' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                'with_image' => $imagePath !== null,
            ];
        }

        $payload = $response->json();
        $content = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $response->body();

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'content' => $content ?: json_encode(['status' => $response->status()]),
            'with_image' => $imagePath !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function prepareMultipartItemBody(array $body): array
    {
        $out = [];
        foreach ($body as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $out[(string) $key] = (string) ($value ?? '');
        }

        foreach ([
            'description_en' => 'name_en',
            'description_ar' => 'name_ar',
            'description_he' => 'name_he',
        ] as $descriptionKey => $nameKey) {
            if (trim($out[$descriptionKey] ?? '') === '' && trim($out[$nameKey] ?? '') !== '') {
                $out[$descriptionKey] = $out[$nameKey];
            }
        }

        return $out;
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (str_contains($path, '://') || str_contains($path, '..')) {
            return null;
        }

        $path = '/'.ltrim($path, '/');
        if (str_starts_with($path, '/api/manager/')) {
            $path = substr($path, strlen('/api/manager')) ?: '/';
        } elseif ($path === '/api/manager') {
            $path = '/';
        }

        if (! preg_match('#^/[A-Za-z0-9._\-/]*$#', $path)) {
            return null;
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{categories: list<array<string, mixed>>}|null
     */
    private function decodeMenuJson(string $response): ?array
    {
        $response = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
            $response = trim($m[1]);
        }
        $decoded = json_decode($response, true);
        if (! is_array($decoded) || ! isset($decoded['categories']) || ! is_array($decoded['categories'])) {
            return null;
        }

        return $this->mergeDrafts(['categories' => []], $decoded);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function publicArguments(string $name, array $arguments): array
    {
        if ($name === 'kaman_request_many') {
            $requests = $arguments['requests'] ?? [];
            $count = is_array($requests) ? count($requests) : 0;
            $first = is_array($requests) ? ($requests[0] ?? null) : null;

            return [
                'count' => $count,
                'first' => is_array($first) ? [
                    'method' => $first['method'] ?? null,
                    'path' => $first['path'] ?? null,
                ] : null,
            ];
        }

        return [
            'method' => $arguments['method'] ?? null,
            'path' => $arguments['path'] ?? null,
        ];
    }

    private function decodeMaybeJson(string $content): mixed
    {
        $decoded = json_decode($content, true);

        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $content : $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function chatCompletions(string $apiKey, array $messages, ?array $tools, array $options = [], ?callable $emit = null): array
    {
        $baseUrl = rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/');
        $model = (string) ($options['model'] ?? (config('openai.menu_agent_model') ?: config('openai.vision_model', 'gpt-4o')));
        $timeout = max(120, (int) config('openai.request_timeout', 30));
        $sslVerify = filter_var(config('openai.ssl_verify', true), FILTER_VALIDATE_BOOLEAN);
        $attempts = 0;
        $maxAttempts = 6;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $http = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->baseUrl($baseUrl);

            if (config('openai.organization')) {
                $http = $http->withHeaders(['OpenAI-Organization' => config('openai.organization')]);
            }
            if (! $sslVerify) {
                $http = $http->withOptions(['verify' => false]);
            }

            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.2,
                'max_tokens' => $options['max_tokens'] ?? 4096,
            ];
            if ($tools !== null) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $response = $http->post('/chat/completions', $payload);
            $body = $response->json() ?? [];
            if ($response->successful()) {
                $choice = $body['choices'][0]['message'] ?? null;
                if (! is_array($choice)) {
                    throw new \RuntimeException('OpenAI returned no message.');
                }

                return $choice;
            }

            $error = is_array($body) ? ($body['error']['message'] ?? $body['error'] ?? $response->body()) : $response->body();
            $errorText = is_string($error) ? $error : (string) json_encode($error);
            if (! $this->isOpenAiRateLimit($response->status(), $errorText) || $attempts >= $maxAttempts) {
                Log::error('Menu agent OpenAI error', ['status' => $response->status(), 'body' => $body]);
                throw new \RuntimeException($this->friendlyOpenAiError($errorText));
            }

            $wait = $this->openAiRetryAfterSeconds($response, $errorText);
            if ($emit) {
                $emit([
                    'event' => 'status',
                    'message' => 'OpenAI is busy. Waiting '.$wait.'s, then retrying ('.$attempts.'/'.$maxAttempts.')…',
                ]);
            }
            if ($wait > 0) {
                sleep($wait);
            }
        }

        throw new \RuntimeException($this->friendlyOpenAiError('rate limit retries exhausted'));
    }

    private function friendlyOpenAiError(string $errorText): string
    {
        $lower = mb_strtolower($errorText);
        if (str_contains($lower, 'rate limit') || str_contains($lower, 'tokens per min') || str_contains($lower, 'tpm')) {
            return 'OpenAI is busy right now. Wait about 20 seconds and send again.';
        }

        return 'The menu agent hit a temporary OpenAI error. Try again.';
    }

    private function isOpenAiRateLimit(int $status, string $error): bool
    {
        return $status === 429 || str_contains(mb_strtolower($error), 'rate limit');
    }

    private function openAiRetryAfterSeconds(\Illuminate\Http\Client\Response $response, string $error): int
    {
        if (app()->environment('testing')) {
            return 0;
        }
        $header = $response->header('Retry-After');
        if (is_numeric($header)) {
            return min(60, max(1, (int) $header));
        }
        if (preg_match('/try again in ([0-9]+(?:\.[0-9]+)?)s/i', $error, $match) === 1) {
            return min(60, max(1, (int) ceil((float) $match[1])));
        }

        return 8;
    }

    private function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max)."\n…truncated…";
    }
}
