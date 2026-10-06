<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Exceptions\FormWorkflowPausedException;
use App\Services\Haat\KamanHttp;
use App\Services\Haat\KamanMenuWriter;
use App\Services\Haat\KamanPublicMenuScraper;
use App\Support\KamanUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FormMenuAgentService
{
    public const MAX_TOOL_ITERATIONS = 24;

    public const MAX_BATCH_REQUESTS = 80;

    public const MAX_ATTACHMENTS = 50;

    /** @var callable(array<string, mixed>): void */
    private $emit;

    /** @var list<array{name: string, path: string, mime: string, kind?: string}> */
    private array $turnAttachments = [];

    private string $turnApiKey = '';

    /** @var array<string, mixed>|null */
    private ?array $turnDraft = null;

    private bool $mealPhotosHandled = false;

    private string $mealPhotoReport = '';

    public function __construct(
        private readonly FormMenuDrinkCatalog $drinks,
        private readonly WoltMenuSource $wolt,
        private readonly KamanPublicMenuScraper $publicMenus,
        private readonly KamanMenuWriter $writer,
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
        $this->emit = $emit;
        $runId = isset($memory['run_id']) && is_numeric($memory['run_id']) ? (int) $memory['run_id'] : 0;
        $generation = isset($memory['worker_generation']) && is_numeric($memory['worker_generation'])
            ? (int) $memory['worker_generation']
            : null;
        if ($runId > 0) {
            KamanHttp::bind($runId, function (string $step, string $message) use ($emit): void {
                $emit([
                    'event' => 'status',
                    'message' => $message,
                ]);
            }, $generation);
        }

        try {
            return $this->executeTurn($history, $newAttachments, $token, $baseUrl, $emit, $memory, $apiKey);
        } finally {
            KamanHttp::unbind();
        }
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<array{name: string, path: string, mime: string}>  $newAttachments
     * @param  array<string, mixed>  $memory
     * @param  callable(array<string, mixed>): void  $emit
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array}
     */
    private function executeTurn(
        array $history,
        array $newAttachments,
        string $token,
        string $baseUrl,
        callable $emit,
        array $memory,
        string $apiKey,
    ): array {
        $attachments = $this->mergeAttachments($memory['attachments'] ?? [], $newAttachments);
        $draft = is_array($memory['menu_draft'] ?? null) ? $memory['menu_draft'] : null;
        $userText = $this->latestUserText($history);
        $menuAttachments = $this->menuAttachments($attachments);
        $hasNewMenuFiles = $this->menuAttachments($newAttachments) !== [];
        $this->turnAttachments = $attachments;
        $this->turnApiKey = $apiKey;
        $this->turnDraft = $draft;
        $this->mealPhotosHandled = false;
        $this->mealPhotoReport = '';
        $sourceUrl = $this->resolveMenuSource($userText, $history, $memory);

        if ($sourceUrl !== null) {
            return $this->importLinkedMenu($sourceUrl, $token, $baseUrl, $emit, $attachments, $draft);
        }

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

        if ($menuAttachments !== [] && ($hasNewMenuFiles || $draft === null)) {
            $emit([
                'event' => 'status',
                'message' => 'Reading '.count($menuAttachments).' menu file'.(count($menuAttachments) === 1 ? '' : 's').'…',
            ]);
            $extracted = $this->extractMenu($menuAttachments, $userText, $emit);
            if ($extracted !== null) {
                $draft = $extracted;
                $this->turnDraft = $draft;
                $counts = $this->draftCounts($draft);
                $emit([
                    'event' => 'status',
                    'message' => 'Read '.$counts['categories'].' categories, '.$counts['meals'].' meals.',
                ]);
            }
        }

        $photoResult = $this->matchMealPhotosIfNeeded($newAttachments, $attachments, $userText, $token, $baseUrl, $emit, $draft, $apiKey);
        if ($photoResult !== null) {
            $draft = $photoResult['menu_draft'];
            $this->turnDraft = $draft;
            $this->mealPhotosHandled = true;
            $this->mealPhotoReport = $photoResult['reply'];
        }

        if ($draft !== null && $this->wantsFullStore($userText, $hasNewMenuFiles)) {
            $emit([
                'event' => 'status',
                'message' => 'Storing every category and meal…',
            ]);
            $wrote = $this->writeFullMenu($draft, $token, $baseUrl, $emit);
            $reply = $this->formatReport($draft, $wrote);
            if ($this->mealPhotoReport !== '') {
                $reply .= "\n\n".$this->mealPhotoReport;
                $wrote['tool_calls'] = array_merge($photoResult['tool_calls'] ?? [], $wrote['tool_calls']);
            }

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

        if ($photoResult !== null && ! $this->textAsksForMoreThanPhotos($userText)) {
            $emit([
                'event' => 'message',
                'reply' => $photoResult['reply'],
            ]);

            return $photoResult;
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
                'model' => (string) (config('openai.menu_agent_model') ?: 'gpt-5.6'),
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
                if ($name === 'assign_meal_photos' && is_array($this->turnDraft)) {
                    $draft = $this->turnDraft;
                }
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
                'kind' => ($row['kind'] ?? '') === 'meal_photo' ? 'meal_photo' : 'menu',
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

    private function menuSourceUrl(string $text): ?string
    {
        if (preg_match('#https?://[^\s<>"\']+#iu', $text, $match) !== 1) {
            return null;
        }
        $url = rtrim($match[0], '.,);]');
        if (str_contains($url, '…') || str_ends_with($url, '...')) {
            return null;
        }
        if ($this->wolt->isWoltUrl($url)) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('#/restaurant/[^/]+#', $path) !== 1) {
                return null;
            }

            return $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_contains($host, 'kaman.') && str_contains($path, '/menu')) {
            return $url;
        }

        return null;
    }

    private function wantsMenuImport(string $text): bool
    {
        return preg_match('/build|copy|paste|import|scrape|analy[sz]e|from scratch|انسخ|حلل|ابن|תעתיק|תבנה|העתק|העתקה/u', mb_strtolower($text)) === 1;
    }

    public function wantsLinkedMenuWork(string $text): bool
    {
        return $this->wantsMenuImport($text) || $this->wantsMenuRepair($text);
    }

    public function shouldCopyLinkedMenu(array $history, array $memory = []): bool
    {
        return $this->resolveMenuSource($this->latestUserText($history), $history, $memory) !== null;
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $history
     */
    public function findMenuUrlInHistory(array $history): ?string
    {
        foreach ($history as $row) {
            if (! is_array($row) || ($row['role'] ?? '') !== 'user') {
                continue;
            }
            $url = $this->menuSourceUrl((string) ($row['content'] ?? ''));
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function wantsMenuRepair(string $text): bool
    {
        $text = mb_strtolower($text);
        if (preg_match('/translat|ترجم|תרגום/u', $text) === 1) {
            return true;
        }
        $images = preg_match('/image|photo|picture|صور|صورة|תמונ/u', $text) === 1;
        $languages = preg_match('/arabic|hebrew|english|عربي|עברית|אנגלית/u', $text) === 1;
        $source = preg_match('/wolt|source link|menu link/u', $text) === 1;

        return $images && ($languages || $source);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $memory
     */
    private function resolveMenuSource(string $userText, array $history, array $memory): ?string
    {
        if (! $this->wantsLinkedMenuWork($userText)) {
            return null;
        }

        $direct = $this->menuSourceUrl($userText);
        if ($direct !== null) {
            return $direct;
        }

        $fromHistory = $this->findMenuUrlInHistory($history);
        if ($fromHistory !== null) {
            return $fromHistory;
        }

        foreach ([(string) ($memory['source_url'] ?? ''), (string) ($memory['title'] ?? '')] as $extra) {
            $url = $this->menuSourceUrl($extra);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @param  array{categories?: list<array<string, mixed>>}|null  $draft
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array}
     */
    private function importLinkedMenu(string $url, string $token, string $baseUrl, callable $emit, array $attachments, ?array $draft): array
    {
        $emit([
            'event' => 'status',
            'message' => 'Opening the menu link…',
        ]);

        try {
            $parsed = $this->wolt->isWoltUrl($url)
                ? $this->wolt->parse($url)
                : $this->publicMenus->scrape($url);
            $parsed = $this->translateMenu($parsed, $emit);
            $emit([
                'event' => 'status',
                'message' => 'Copying categories, meals, options, and photos…',
            ]);
            $report = $this->writer->write($baseUrl, $token, $parsed, true, function (string $step, string $message) use ($emit): void {
                $emit([
                    'event' => 'status',
                    'message' => $message,
                ]);
            });
            $reply = $this->importReport($report, $parsed);
        } catch (FormWorkflowPausedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Menu link import failed', ['url' => $url, 'error' => $e->getMessage()]);
            $reply = 'The menu link could not be copied: '.$e->getMessage();
        }

        $emit([
            'event' => 'message',
            'reply' => $reply,
        ]);

        return [
            'reply' => $reply,
            'tool_calls' => [[
                'name' => 'import_menu_url',
                'arguments' => ['url' => $url],
                'ok' => ! str_starts_with($reply, 'The menu link could not'),
                'summary' => $reply,
            ]],
            'attachments' => $attachments,
            'menu_draft' => $draft,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function importMenuTool(array $arguments, string $token, string $baseUrl): array
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        if ($this->menuSourceUrl($url) === null) {
            return [
                'ok' => false,
                'summary' => 'URL is not a Wolt restaurant or a public Kaman menu',
                'content' => json_encode(['error' => 'unsupported url'], JSON_UNESCAPED_UNICODE),
            ];
        }

        $result = $this->importLinkedMenu($url, $token, $baseUrl, $this->emit, [], null);

        return [
            'ok' => (bool) ($result['tool_calls'][0]['ok'] ?? false),
            'summary' => (string) $result['reply'],
            'content' => $this->jsonForModel(['reply' => $result['reply']], 8000),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function translateMenu(array $parsed, callable $emit): array
    {
        $rows = [];
        foreach (['categories', 'meals', 'unique_ingredient_categories', 'unique_ingredients'] as $list) {
            foreach ($parsed[$list] ?? [] as $index => $row) {
                if (! is_array($row) || ! $this->needsTranslation($row)) {
                    continue;
                }
                $id = (string) ($row['haat_id'] ?? $row['title'] ?? $index);
                $rows[] = [
                    'id' => $list.':'.$id,
                    'name' => $this->sourceText($row, 'name'),
                    'description' => $this->sourceText($row, 'description'),
                ];
            }
        }
        if ($rows === []) {
            return $parsed;
        }

        $emit([
            'event' => 'status',
            'message' => 'Translating names into Arabic, Hebrew, and English…',
        ]);

        $translated = [];
        $chunks = array_chunk($rows, 12);
        foreach ($chunks as $index => $chunk) {
            $emit([
                'event' => 'status',
                'message' => 'Translating names '.(($index * 12) + 1).'–'.min(count($rows), ($index + 1) * 12).' of '.count($rows).'…',
            ]);
            $translated += $this->translateMenuChunk($chunk);
            $missing = array_values(array_filter($chunk, fn (array $row): bool => ! isset($translated[$row['id']])));
            if ($missing !== []) {
                $translated += $this->translateMenuChunk($missing);
            }
        }
        $emit([
            'event' => 'status',
            'message' => 'Translated '.count($translated).' of '.count($rows).' names.',
        ]);

        foreach (['categories', 'meals', 'unique_ingredient_categories', 'unique_ingredients'] as $list) {
            foreach ($parsed[$list] ?? [] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = $list.':'.(string) ($row['haat_id'] ?? $row['title'] ?? $index);
                $parsed[$list][$index] = $this->applyTranslation($row, $translated[$id] ?? null);
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    /**
     * @param  list<array{id: string, name: string, description: string}>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function translateMenuChunk(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        try {
            $choice = $this->chatCompletions(trim((string) config('openai.api_key')), [
                ['role' => 'system', 'content' => 'Translate each restaurant menu row into Arabic, Hebrew, and English. Return only JSON {"rows":[{"id":"","name_ar":"","name_en":"","name_he":"","description_ar":"","description_en":"","description_he":""}]}. Keep the source text in its own language. Arabic must use Arabic letters. Hebrew must use Hebrew letters. English must use Latin letters. Do not copy one language into the other fields.'],
                ['role' => 'user', 'content' => $this->jsonForModel(['rows' => $rows], 20000)],
            ], null, [
                'temperature' => 0.2,
                'max_tokens' => 8000,
                'reasoning_effort' => 'low',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Menu translation failed', ['error' => $e->getMessage()]);

            return [];
        }

        $content = trim((string) ($choice['content'] ?? ''));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        $decoded = $this->decodeMaybeJson($content);
        if (! is_array($decoded) && preg_match('/\{.*\}/su', $content, $match) === 1) {
            $decoded = json_decode($match[0], true);
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        $accepted = [];
        foreach (is_array($decoded) ? ($decoded['rows'] ?? []) : [] as $row) {
            if (! is_array($row) || ! isset($row['id'], $byId[(string) $row['id']])) {
                continue;
            }
            $source = $byId[(string) $row['id']];
            if (! $this->translationAccepted((string) $source['name'], $row)) {
                continue;
            }
            $accepted[(string) $row['id']] = $row;
        }

        return $accepted;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function translationAccepted(string $source, array $row): bool
    {
        $ar = trim((string) ($row['name_ar'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $he = trim((string) ($row['name_he'] ?? ''));
        if ($ar === '' || $en === '' || $he === '' || ($ar === $source && $en === $source && $he === $source)) {
            return false;
        }
        if (preg_match('/\p{Hebrew}/u', $source) === 1) {
            return preg_match('/\p{Arabic}/u', $ar) === 1
                && preg_match('/[A-Za-z]/', $en) === 1
                && preg_match('/\p{Hebrew}/u', $he) === 1;
        }
        if (preg_match('/\p{Arabic}/u', $source) === 1) {
            return preg_match('/\p{Hebrew}/u', $he) === 1 && preg_match('/[A-Za-z]/', $en) === 1;
        }

        return preg_match('/\p{Arabic}/u', $ar) === 1 && preg_match('/\p{Hebrew}/u', $he) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function needsTranslation(array $row): bool
    {
        $names = [];
        foreach (['name_ar', 'name_en', 'name_he'] as $field) {
            $names[] = trim((string) ($row[$field] ?? ''));
        }
        if (in_array('', $names, true)) {
            return true;
        }

        return $names[0] === $names[1] && $names[1] === $names[2];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourceText(array $row, string $prefix): string
    {
        foreach ([$prefix.'_he', $prefix.'_ar', $prefix.'_en', $prefix] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $translation
     * @return array<string, mixed>
     */
    private function applyTranslation(array $row, ?array $translation): array
    {
        foreach (['name', 'description'] as $prefix) {
            $source = $this->sourceText($row, $prefix);
            if ($source === '' && $prefix === 'description') {
                continue;
            }
            foreach (['ar', 'en', 'he'] as $code) {
                $field = $prefix.'_'.$code;
                if (! array_key_exists($field, $row) && $prefix === 'description') {
                    continue;
                }
                $value = trim((string) ($translation[$field] ?? ''));
                if ($value === '') {
                    $value = trim((string) ($row[$field] ?? ''));
                }
                if ($value === '') {
                    $value = $source;
                }
                $row[$field] = $value;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $parsed
     */
    private function importReport(array $report, array $parsed): string
    {
        $photos = [];
        foreach ($parsed['meals'] ?? [] as $meal) {
            if (is_array($meal) && is_string($meal['image_url'] ?? null) && $meal['image_url'] !== '') {
                $photos[(string) $meal['haat_id']] = true;
            }
        }
        $createdPhotos = 0;
        foreach ($report['meals']['created'] ?? [] as $row) {
            if (is_array($row) && isset($photos[(string) ($row['haat_id'] ?? '')])) {
                $createdPhotos++;
            }
        }

        $lines = [
            'Copied the menu from the link.',
            count($report['categories']['created'] ?? []).' categories created, '.count($report['categories']['reused'] ?? []).' already there.',
            count($report['meals']['created'] ?? []).' meals created, '.count($report['meals']['reused'] ?? []).' already there, '.$createdPhotos.' photos copied.',
            count($report['ingredient_categories']['created'] ?? []).' option groups and '.count($report['ingredients']['created'] ?? []).' choices created.',
        ];
        $failed = count($report['meals']['failed'] ?? []) + count($report['categories']['failed'] ?? []);
        if ($failed > 0) {
            $lines[] = $failed.' rows failed. Say what to fix and I will patch them.';
        }

        return implode("\n", $lines);
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

    private function wantsMealPhotoMatch(string $text): bool
    {
        return preg_match(
            '/meal photos?|meal images?|match (these |the )?(photos?|images?|pictures?)|assign (these |the )?(photos?|images?|pictures?)|طابق|صور الوجبات|اربط الصور|התאם|תמונות מנות|שייך/u',
            mb_strtolower($text)
        ) === 1;
    }

    private function textAsksForMoreThanPhotos(string $text): bool
    {
        return preg_match(
            '/\b(price|rename|delete|duplicates?|dedupe|translate|categor(?:y|ies))\b|سعر|احذف|ترجم|قسم|מחיר|מחק|תרגם|קטגור/u',
            mb_strtolower($text)
        ) === 1;
    }

    /**
     * @param  list<array{name: string, path: string, mime: string, kind?: string}>  $newAttachments
     * @param  list<array{name: string, path: string, mime: string, kind?: string}>  $attachments
     * @param  array<string, mixed>|null  $draft
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string, kind?: string}>, menu_draft: ?array}|null
     */
    private function matchMealPhotosIfNeeded(
        array $newAttachments,
        array $attachments,
        string $userText,
        string $token,
        string $baseUrl,
        callable $emit,
        ?array $draft,
        string $apiKey,
    ): ?array {
        $incoming = array_values(array_filter($newAttachments, fn (array $row): bool => $this->isMealPhoto($row)));
        $photos = $incoming;
        if ($photos === [] && $this->wantsMealPhotoMatch($userText)) {
            $photos = array_values(array_filter($attachments, fn (array $row): bool => $this->isMealPhoto($row)));
        }
        if ($photos === []) {
            return null;
        }

        $matched = $this->matchAndUploadMealPhotos($photos, $token, $baseUrl, $emit, $draft, $apiKey);

        return [
            'reply' => $matched['reply'],
            'tool_calls' => $matched['tool_calls'],
            'attachments' => $attachments,
            'menu_draft' => $matched['menu_draft'],
        ];
    }

    /**
     * @param  list<array{name: string, path: string, mime: string, kind?: string}>  $photos
     * @param  array<string, mixed>|null  $draft
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, menu_draft: ?array}
     */
    private function matchAndUploadMealPhotos(
        array $photos,
        string $token,
        string $baseUrl,
        callable $emit,
        ?array $draft,
        string $apiKey,
    ): array {
        $emit([
            'event' => 'status',
            'message' => 'Matching '.count($photos).' meal photo'.(count($photos) === 1 ? '' : 's').'…',
        ]);

        $candidates = $this->mealPhotoCandidates($token, $baseUrl, $draft);
        if ($candidates === []) {
            return [
                'reply' => 'No meals to match yet. Store the menu first, then upload the meal photos again.',
                'tool_calls' => [],
                'menu_draft' => $draft,
            ];
        }

        $filenameHits = $this->filenameMealMatches($photos, $candidates);
        $usedMeals = [];
        foreach ($filenameHits as $candidateIndex) {
            $usedMeals[$candidateIndex] = true;
        }

        $visionIndexes = [];
        foreach ($photos as $index => $photo) {
            if (! isset($filenameHits[$index])) {
                $visionIndexes[] = $index;
            }
        }

        $visionHits = [];
        if ($visionIndexes !== []) {
            $emit([
                'event' => 'status',
                'message' => count($filenameHits).' matched by file name. Looking at '.count($visionIndexes).' photo'.(count($visionIndexes) === 1 ? '' : 's').'…',
            ]);
            $visionHits = $this->visionMealMatches($photos, $visionIndexes, $candidates, $usedMeals, $apiKey, $emit);
        }

        $toolCalls = [];
        $byName = [];
        $byVision = [];
        $unmatched = [];
        $linkedDraft = [];

        foreach ($photos as $index => $photo) {
            $candidateIndex = $filenameHits[$index] ?? $visionHits[$index] ?? null;
            $via = isset($filenameHits[$index]) ? 'name' : (isset($visionHits[$index]) ? 'vision' : null);
            if ($candidateIndex === null || ! isset($candidates[$candidateIndex]) || $via === null) {
                $unmatched[] = (string) $photo['name'];
                continue;
            }

            $candidate = $candidates[$candidateIndex];
            $label = $this->candidateLabel($candidate);
            $line = (string) $photo['name'].' → '.$label;
            if ($via === 'name') {
                $byName[] = $line;
            } else {
                $byVision[] = $line;
            }

            $id = (string) ($candidate['id'] ?? '');
            if ($id !== '') {
                $sent = $this->uploadMealPhoto($candidate, (string) $photo['path'], $token, $baseUrl);
                $this->trackHttp($emit, $toolCalls, 'PUT', '/items/'.$id, $sent, true);
                if (! $sent['ok']) {
                    $line = $line.' (upload failed, HTTP '.$sent['status'].')';
                    if ($via === 'name') {
                        $byName[array_key_last($byName)] = $line;
                    } else {
                        $byVision[array_key_last($byVision)] = $line;
                    }
                }
            }

            $draftPointer = $candidate['draft'] ?? null;
            if (is_array($draftPointer) && is_array($draft)) {
                [$categoryIndex, $mealIndex] = $draftPointer;
                if (isset($draft['categories'][$categoryIndex]['meals'][$mealIndex]) && is_array($draft['categories'][$categoryIndex]['meals'][$mealIndex])) {
                    $draft['categories'][$categoryIndex]['meals'][$mealIndex]['image_path'] = (string) $photo['path'];
                    if ($id === '') {
                        $linkedDraft[] = $line;
                    }
                }
            }
        }

        $matchedCount = count($byName) + count($byVision);
        $lines = [
            'Matched '.$matchedCount.' of '.count($photos).' meal photo'.(count($photos) === 1 ? '' : 's').'.',
        ];
        if ($byName !== []) {
            $lines[] = '';
            $lines[] = 'By file name:';
            foreach ($byName as $line) {
                $lines[] = '- '.$line;
            }
        }
        if ($byVision !== []) {
            $lines[] = '';
            $lines[] = 'By looking at the photo:';
            foreach ($byVision as $line) {
                $lines[] = '- '.$line;
            }
        }
        if ($linkedDraft !== []) {
            $lines[] = '';
            $lines[] = 'These are linked to meals that are not on the dashboard yet. They upload when those meals are stored.';
        }
        if ($unmatched !== []) {
            $lines[] = '';
            $lines[] = 'No confident match:';
            foreach ($unmatched as $name) {
                $lines[] = '- '.$name;
            }
        }

        return [
            'reply' => implode("\n", $lines),
            'tool_calls' => $toolCalls,
            'menu_draft' => $draft,
        ];
    }

    /**
     * @param  list<array{name: string, path: string, mime: string, kind?: string}>  $rows
     * @return list<array{name: string, path: string, mime: string, kind?: string}>
     */
    private function menuAttachments(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => ! $this->isMealPhoto($row)));
    }

    /**
     * @param  array{name?: string, path?: string, mime?: string, kind?: string}  $row
     */
    private function isMealPhoto(array $row): bool
    {
        if (($row['kind'] ?? '') !== 'meal_photo') {
            return false;
        }
        $mime = (string) ($row['mime'] ?? '');
        if (str_starts_with($mime, 'image/')) {
            return true;
        }
        $name = strtolower((string) ($row['name'] ?? ''));

        return preg_match('/\.(jpe?g|png|gif|webp)$/', $name) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return list<array{token: string, id: string, names: list<string>, description: string, row: array<string, mixed>, draft: ?array{0: int, 1: int}}>
     */
    private function mealPhotoCandidates(string $token, string $baseUrl, ?array $draft): array
    {
        $candidates = [];
        $byKey = [];
        foreach ($this->fetchPaged($token, $baseUrl, '/items') as $item) {
            $names = $this->rowNames($item);
            $key = $this->firstNameKey($names);
            $description = trim((string) ($item['description_en'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($item['description_ar'] ?? ''));
            }
            $candidates[] = [
                'token' => $this->rowId($item),
                'id' => $this->rowId($item),
                'names' => $names,
                'description' => $description,
                'row' => $item,
                'draft' => null,
            ];
            if ($key !== '') {
                $byKey[$key] = count($candidates) - 1;
            }
        }

        foreach ($draft['categories'] ?? [] as $categoryIndex => $category) {
            if (! is_array($category)) {
                continue;
            }
            foreach ($category['meals'] ?? [] as $mealIndex => $meal) {
                if (! is_array($meal)) {
                    continue;
                }
                $names = $this->rowNames($meal);
                $key = $this->firstNameKey($names);
                $description = trim((string) ($meal['description_en'] ?? $meal['description_ar'] ?? ''));
                if ($key !== '' && isset($byKey[$key])) {
                    $candidates[$byKey[$key]]['draft'] = [(int) $categoryIndex, (int) $mealIndex];
                    if ($candidates[$byKey[$key]]['description'] === '' && $description !== '') {
                        $candidates[$byKey[$key]]['description'] = $description;
                    }
                    continue;
                }
                $candidates[] = [
                    'token' => 'draft:'.$categoryIndex.':'.$mealIndex,
                    'id' => '',
                    'names' => $names,
                    'description' => $description,
                    'row' => $meal,
                    'draft' => [(int) $categoryIndex, (int) $mealIndex],
                ];
                if ($key !== '') {
                    $byKey[$key] = count($candidates) - 1;
                }
            }
        }

        return array_values(array_filter($candidates, fn (array $candidate): bool => $this->firstNameKey($candidate['names']) !== ''));
    }

    /**
     * @param  list<string>  $names
     */
    private function firstNameKey(array $names): string
    {
        foreach ($names as $name) {
            $key = $this->nameKey((string) $name);
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $photos
     * @param  list<array{names: list<string>}>  $candidates
     * @return array<int, int> photo index => candidate index
     */
    private function filenameMealMatches(array $photos, array $candidates): array
    {
        $pairs = [];
        foreach ($photos as $photoIndex => $photo) {
            $scores = [];
            foreach ($candidates as $candidateIndex => $candidate) {
                $score = $this->scoreFilename((string) $photo['name'], $candidate['names']);
                if ($score > 0) {
                    $scores[] = ['index' => $candidateIndex, 'score' => $score];
                }
            }
            usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $best = $scores[0]['score'] ?? 0;
            $second = $scores[1]['score'] ?? 0;
            $confident = ($best >= 100 && $second < 100) || ($best >= 86 && ($best - $second) >= 12);
            if ($confident) {
                $pairs[] = [
                    'photo' => $photoIndex,
                    'candidate' => $scores[0]['index'],
                    'score' => $best,
                ];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $takenPhotos = [];
        $takenMeals = [];
        $hits = [];
        foreach ($pairs as $pair) {
            if (isset($takenPhotos[$pair['photo']]) || isset($takenMeals[$pair['candidate']])) {
                continue;
            }
            $takenPhotos[$pair['photo']] = true;
            $takenMeals[$pair['candidate']] = true;
            $hits[$pair['photo']] = $pair['candidate'];
        }

        return $hits;
    }

    /**
     * @param  list<string>  $names
     */
    private function scoreFilename(string $filename, array $names): int
    {
        $stem = $this->filenameStem($filename);
        $stemKey = $this->nameKey($stem);
        $stemWords = $this->nameWords($stem);
        if ($stemKey === '' || mb_strlen($stemKey) < 3) {
            return 0;
        }

        $best = 0;
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $key = $this->nameKey($name);
            if ($key !== '' && $key === $stemKey) {
                return 100;
            }
            $mealWords = $this->nameWords($name);
            if ($stemWords !== [] && $mealWords !== [] && $this->wordsCover($stemWords, $mealWords)) {
                $best = max($best, count($stemWords) === count($mealWords) ? 100 : 90);
            }
            if ($key !== '' && $stemKey !== '' && preg_match('/[^\x00-\x7F]/', $stemKey.$key) !== 1) {
                similar_text($stemKey, $key, $percent);
                if ($percent >= 90) {
                    $best = max($best, (int) round($percent));
                }
            }
        }

        return $best;
    }

    private function filenameStem(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = str_replace(['_', '-', '.'], ' ', $base);
        $base = preg_replace('/\b(img|image|images|photo|photos|pic|pics|dsc|whatsapp|copy|final|edited)\b/iu', ' ', $base) ?? $base;
        $base = preg_replace('/\b\d{3,}\b/u', ' ', $base) ?? $base;
        $base = preg_replace('/\s+/u', ' ', $base) ?? $base;

        return trim($base);
    }

    /**
     * @return list<string>
     */
    private function nameWords(string $value): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim($value)) ?: [] as $word) {
            $key = $this->nameKey((string) $word);
            if (mb_strlen($key) >= 3) {
                $words[] = $key;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private function wordsCover(array $needles, array $haystack): bool
    {
        foreach ($needles as $needle) {
            if (! in_array($needle, $haystack, true)) {
                return false;
            }
        }

        return $needles !== [];
    }

    /**
     * @param  list<array{name: string, path: string}>  $photos
     * @param  list<int>  $photoIndexes
     * @param  list<array{token: string, id: string, names: list<string>, description: string}>  $candidates
     * @param  array<int, true>  $usedMeals
     * @return array<int, int>
     */
    private function visionMealMatches(
        array $photos,
        array $photoIndexes,
        array $candidates,
        array $usedMeals,
        string $apiKey,
        callable $emit,
    ): array {
        $open = [];
        foreach ($candidates as $index => $candidate) {
            if (! isset($usedMeals[$index])) {
                $open[$index] = $candidate;
            }
        }
        if ($open === [] || $apiKey === '') {
            return [];
        }

        $menuLines = [];
        foreach ($open as $candidate) {
            $names = array_values(array_filter(array_map('trim', $candidate['names']), static fn (string $name): bool => $name !== ''));
            $desc = trim((string) ($candidate['description'] ?? ''));
            $menuLines[] = 'id '.$candidate['token'].' | '.implode(' | ', $names).($desc !== '' ? ' | '.$this->truncate($desc, 120) : '');
        }
        $menu = implode("\n", $menuLines);
        $hits = [];

        foreach (array_chunk($photoIndexes, 4) as $chunk) {
            $content = [[
                'type' => 'text',
                'text' => "Match each food photo to the restaurant meal it depicts.\n"
                    ."Filename is a hint. When the file name and the picture disagree, trust the picture.\n"
                    ."Pick the meal the photo resembles most. Use null when nothing is a reasonable match.\n"
                    ."Each meal id may be used once.\n\nMeals:\n{$menu}\n\n"
                    ."Return JSON only: {\"matches\":[{\"file\":\"exact-filename.jpg\",\"item_id\":\"id or null\"}]}",
            ]];
            $batchPhotos = [];
            foreach ($chunk as $offset => $photoIndex) {
                $photo = $photos[$photoIndex];
                $batchPhotos[] = $photo;
                $content[] = ['type' => 'text', 'text' => 'Image '.($offset + 1).' filename: '.$photo['name']];
                $prepared = $this->prepareVisionImage((string) $photo['path']);
                $block = $this->toVisionInput($prepared, 'low');
                $content[] = $block;
            }

            try {
                $choice = $this->chatCompletions($apiKey, [
                    ['role' => 'system', 'content' => 'You match restaurant meal photos to menu items. Output valid JSON only.'],
                    ['role' => 'user', 'content' => $content],
                ], null, [
                    'model' => (string) (config('openai.vision_model') ?: 'gpt-4o'),
                    'max_tokens' => 800,
                    'temperature' => 0.1,
                ], $emit);
            } catch (\Throwable $e) {
                Log::warning('Menu agent meal photo vision failed', ['error' => $e->getMessage()]);
                continue;
            }

            $mapping = $this->parseMealPhotoMapping((string) ($choice['content'] ?? ''));
            foreach ($batchPhotos as $photo) {
                $fileKey = mb_strtolower((string) $photo['name']);
                $token = $mapping[$fileKey] ?? null;
                if ($token === null || $token === '' || $token === 'null') {
                    continue;
                }
                $candidateIndex = $this->candidateIndexForToken($token, $candidates);
                if ($candidateIndex === null || isset($usedMeals[$candidateIndex]) || isset($hits[$this->photoIndexOf($photos, $photo)])) {
                    continue;
                }
                $photoIndex = $this->photoIndexOf($photos, $photo);
                if ($photoIndex === null) {
                    continue;
                }
                $usedMeals[$candidateIndex] = true;
                $hits[$photoIndex] = $candidateIndex;
            }
        }

        return $hits;
    }

    /**
     * @return array<string, string> lowercase filename => item token
     */
    private function parseMealPhotoMapping(string $response): array
    {
        $response = trim($response);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $match) === 1) {
            $response = trim($match[1]);
        }
        $decoded = json_decode($response, true);
        if (! is_array($decoded)) {
            if (preg_match('/\{[\s\S]*\}/', $response, $match) === 1) {
                $decoded = json_decode($match[0], true);
            }
        }
        $rows = is_array($decoded) ? ($decoded['matches'] ?? $decoded) : [];
        if (! is_array($rows)) {
            return [];
        }
        $mapping = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $file = mb_strtolower(trim((string) ($row['file'] ?? '')));
            $token = trim((string) ($row['item_id'] ?? ''));
            if ($file !== '' && $token !== '') {
                $mapping[$file] = $token;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<array{token: string, id: string, names: list<string>}>  $candidates
     */
    private function candidateIndexForToken(string $token, array $candidates): ?int
    {
        foreach ($candidates as $index => $candidate) {
            if ($candidate['token'] === $token || ($candidate['id'] !== '' && $candidate['id'] === $token)) {
                return $index;
            }
        }
        $key = $this->nameKey($token);
        if ($key === '') {
            return null;
        }
        foreach ($candidates as $index => $candidate) {
            foreach ($candidate['names'] as $name) {
                if ($this->nameKey((string) $name) === $key) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, path: string}>  $photos
     * @param  array{name: string, path: string}  $photo
     */
    private function photoIndexOf(array $photos, array $photo): ?int
    {
        foreach ($photos as $index => $row) {
            if ($row['path'] === $photo['path'] && $row['name'] === $photo['name']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array{names: list<string>, id: string}  $candidate
     */
    private function candidateLabel(array $candidate): string
    {
        foreach ($candidate['names'] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                return $name;
            }
        }

        return '#'.($candidate['id'] !== '' ? $candidate['id'] : '?');
    }

    /**
     * @param  array{id: string, row: array<string, mixed>}  $candidate
     * @return array{ok: bool, status: int, content: string, with_image: bool}
     */
    private function uploadMealPhoto(array $candidate, string $imagePath, string $token, string $baseUrl): array
    {
        $row = $candidate['row'];
        $categoryId = (string) ($row['category_id'] ?? ($row['category']['id'] ?? ''));
        $body = [
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'name_he' => (string) ($row['name_he'] ?? ''),
            'description_ar' => (string) ($row['description_ar'] ?? ''),
            'description_en' => (string) ($row['description_en'] ?? ''),
            'description_he' => (string) ($row['description_he'] ?? ''),
            'price' => $this->normalizePrice((string) ($row['price'] ?? '0')),
            'category_id' => $categoryId,
            'status' => (string) ($row['status'] ?? '1'),
        ];

        $body['_method'] = 'PUT';

        return $this->sendKaman('POST', '/items/'.$candidate['id'], $body, $token, $baseUrl, $imagePath, false);
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
                $uploaded = is_string($meal['image_path'] ?? null) && is_file($meal['image_path'])
                    ? $meal['image_path']
                    : null;
                $image = $uploaded ?? $this->drinks->imageFor($mealNames);
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
                    .$this->jsonForModel($draft, 20000),
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
    private function toVisionInput(string $imagePath, string $detail = 'high'): array
    {
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $data = base64_encode((string) file_get_contents($imagePath));

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$mime.';base64,'.$data,
                'detail' => $detail,
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
- import_menu_url: open a Wolt restaurant link or a public Kaman /menu link and copy the whole menu, including photos, prices, and option groups. Never say you cannot open the page.
- assign_meal_photos: match uploaded meal photos to meals by file name, then by looking at each photo, and upload the image onto the meal.
- read_menu: the live menu (categories, meals, and option groups). Call this before any change.
- edit_menu: apply chat edits to the live menu. Use this for rename, remove a phrase, change a price, change a description, or the same edit on many rows. Do not recreate meals to rename them.
- kaman_request: one HTTP call (GET, POST, PUT, PATCH, DELETE) when edit_menu does not fit
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

Menu links:
- If the user pastes a Wolt link or a public menu link and asks to build, copy, analyze, or import it, call import_menu_url with that URL. Copy every category, meal, option, price, and photo. Do not ask for screenshots instead.

Chat edits:
- A sentence that changes the menu already stored (remove words, rename, reprice, fix a translation, move a meal to another section) is an edit, not a new import.
- Call read_menu, then edit_menu. Match the user's words. Change only those rows and fields.
- To move a meal, set category_id to the destination category id from read_menu.
- remove_text strips a phrase from names. An Arabic phrase only hits Arabic names. Keep the rest of the name, including brand names.
- target "items" is meals and drinks. "categories" is section titles. "choices" is option values (pita, hummus, large). "groups" is option questions (choose a size).
- After edit_menu, answer in the user's language with what changed. Do not store the menu again.

Rules:
- Follow user instructions exactly (item name as description, dialect spellings, etc.).
- Never mix Hebrew letters into Arabic, or Arabic letters into Hebrew.
- Keep original menu language; still fill name_en, name_ar, name_he accurately.
- price is a numeric string like "25.00".
- Before creating, GET existing rows. Skip any category, meal, or ingredient whose name already exists (any language). Do not POST duplicates.
- If the user asks to delete duplicates, GET the list, group by name, keep the lowest id, DELETE the extras.
- Known drinks (cola, sprite, water, pepsi, fanta, …) are stored with catalog images automatically on POST /items.
- Meal photos uploaded with the Meal photos button are matched and uploaded for you. Do not ask the user to re-upload them. Call assign_meal_photos only when the user asks to match meal photos that are already in this chat and they were not matched yet.
- After writes, list counts per category, not a partial sample.
- If something is ambiguous, pick the smallest reasonable choice and mention it.
PROMPT;
    }

    /**
     * @return array{ok: bool, summary: string, content: string}
     */
    private function assignMealPhotosTool(string $token, string $baseUrl): array
    {
        if ($this->mealPhotosHandled) {
            $reply = $this->mealPhotoReport !== '' ? $this->mealPhotoReport : 'Meal photos were already matched in this turn.';

            return [
                'ok' => true,
                'summary' => 'Meal photos already matched',
                'content' => $reply,
            ];
        }

        $photos = array_values(array_filter($this->turnAttachments, fn (array $row): bool => $this->isMealPhoto($row)));
        if ($photos === []) {
            return [
                'ok' => false,
                'summary' => 'No meal photos in this chat',
                'content' => 'No meal photos are attached. Ask the user to upload them with Meal photos.',
            ];
        }

        $matched = $this->matchAndUploadMealPhotos($photos, $token, $baseUrl, $this->emit, $this->turnDraft, $this->turnApiKey);
        $this->turnDraft = $matched['menu_draft'];
        $this->mealPhotosHandled = true;
        $this->mealPhotoReport = $matched['reply'];

        return [
            'ok' => true,
            'summary' => 'Matched meal photos',
            'content' => $matched['reply'],
        ];
    }

    /**
     * @return array{ok: bool, summary: string, content: string}
     */
    private function readMenu(string $token, string $baseUrl): array
    {
        $categories = $this->fetchPaged($token, $baseUrl, '/categories');
        $items = $this->fetchPaged($token, $baseUrl, '/items');
        $byCategory = [];
        foreach ($categories as $category) {
            $id = $this->rowId($category);
            if ($id === '') {
                continue;
            }
            $byCategory[$id] = [
                'id' => $id,
                'name_ar' => (string) ($category['name_ar'] ?? ''),
                'name_en' => (string) ($category['name_en'] ?? ''),
                'name_he' => (string) ($category['name_he'] ?? ''),
                'items' => [],
            ];
        }

        foreach ($items as $item) {
            $categoryId = (string) ($item['category_id'] ?? ($item['category']['id'] ?? ''));
            $row = [
                'id' => $this->rowId($item),
                'name_ar' => (string) ($item['name_ar'] ?? ''),
                'name_en' => (string) ($item['name_en'] ?? ''),
                'name_he' => (string) ($item['name_he'] ?? ''),
                'price' => (string) ($item['price'] ?? ''),
                'description_ar' => (string) ($item['description_ar'] ?? ''),
                'description_en' => (string) ($item['description_en'] ?? ''),
                'description_he' => (string) ($item['description_he'] ?? ''),
            ];
            if (! isset($byCategory[$categoryId])) {
                $byCategory[$categoryId] = [
                    'id' => $categoryId,
                    'name_ar' => '',
                    'name_en' => '',
                    'name_he' => '',
                    'items' => [],
                ];
            }
            $byCategory[$categoryId]['items'][] = $row;
        }

        $groups = [];
        $cashier = $this->sendCashier('GET', '/ingredient-categories?per_page=100', [], $token, $baseUrl);
        if ($cashier['ok']) {
            foreach ($this->unwrapList(json_decode($cashier['content'], true)) as $group) {
                $groups[] = [
                    'id' => $this->rowId($group),
                    'name_ar' => (string) ($group['name_ar'] ?? ''),
                    'name_en' => (string) ($group['name_en'] ?? ''),
                    'name_he' => (string) ($group['name_he'] ?? ''),
                    'type' => (string) ($group['type'] ?? ''),
                    'min' => $group['min_options'] ?? null,
                    'max' => $group['max_options'] ?? null,
                ];
            }
        }
        $choices = [];
        $choiceResponse = $this->sendCashier('GET', '/ingredients?per_page=100', [], $token, $baseUrl);
        if ($choiceResponse['ok']) {
            foreach ($this->unwrapList(json_decode($choiceResponse['content'], true)) as $choice) {
                $choices[] = [
                    'id' => $this->rowId($choice),
                    'name_ar' => (string) ($choice['name_ar'] ?? ''),
                    'name_en' => (string) ($choice['name_en'] ?? ''),
                    'name_he' => (string) ($choice['name_he'] ?? ''),
                    'price' => $choice['price'] ?? 0,
                    'category_id' => (string) ($choice['category_id'] ?? ''),
                ];
            }
        }

        $payload = [
            'categories' => array_values($byCategory),
            'groups' => $groups,
            'choices' => $choices,
        ];

        return [
            'ok' => true,
            'summary' => count($items).' meals, '.count($categories).' categories, '.count($choices).' choices',
            'content' => $this->jsonForModel($payload, 24000),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function editMenu(array $arguments, string $token, string $baseUrl): array
    {
        $operations = $arguments['operations'] ?? [];
        if (is_array($operations) && array_is_list($operations) === false) {
            $operations = [$operations];
        }
        if (! is_array($operations) || $operations === []) {
            return [
                'ok' => false,
                'summary' => 'No edits given',
                'content' => json_encode(['error' => 'operations is required'], JSON_UNESCAPED_UNICODE),
            ];
        }

        $changed = [];
        $failed = [];
        foreach (array_slice($operations, 0, 40) as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $target = (string) ($operation['target'] ?? 'items');
            $rows = $this->rowsForEditTarget($target, $token, $baseUrl);
            $matches = $this->matchingRows($rows, $operation);
            if ($matches === []) {
                $failed[] = 'No '.$target.' matched '.($operation['match'] ?? $operation['remove_text'] ?? '');
                continue;
            }
            foreach ($matches as $row) {
                $result = $this->applyEdit($target, $row, $operation, $token, $baseUrl);
                if ($result['ok']) {
                    $changed[] = $result['summary'];
                } else {
                    $failed[] = $result['summary'];
                }
            }
        }

        return [
            'ok' => $failed === [] && $changed !== [],
            'summary' => count($changed).' updated'.($failed === [] ? '' : ', '.count($failed).' failed'),
            'content' => $this->jsonForModel([
                'updated' => $changed,
                'failed' => $failed,
            ], 8000),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForEditTarget(string $target, string $token, string $baseUrl): array
    {
        return match ($target) {
            'categories' => $this->fetchPaged($token, $baseUrl, '/categories'),
            'choices' => $this->fetchCashierPaged($token, $baseUrl, '/ingredients'),
            'groups' => $this->fetchCashierPaged($token, $baseUrl, '/ingredient-categories'),
            default => $this->fetchPaged($token, $baseUrl, '/items'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $operation
     * @return list<array<string, mixed>>
     */
    private function matchingRows(array $rows, array $operation): array
    {
        $match = trim((string) ($operation['match'] ?? ''));
        if ($match === '') {
            $match = trim((string) ($operation['remove_text'] ?? ''));
        }
        if ($match === '') {
            return [];
        }
        $needle = mb_strtolower($match);
        $matched = [];
        foreach ($rows as $row) {
            foreach ($this->rowNames($row) as $name) {
                if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
                    $matched[] = $row;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $operation
     * @return array{ok: bool, summary: string}
     */
    private function applyEdit(string $target, array $row, array $operation, string $token, string $baseUrl): array
    {
        $id = $this->rowId($row);
        $label = $this->rowLabel($row);
        if ($id === '') {
            return ['ok' => false, 'summary' => $label.' has no id'];
        }

        $fields = $operation['fields'] ?? [];
        if (! is_array($fields) || $fields === []) {
            $fields = ['name_ar', 'name_en', 'name_he', 'description_ar', 'description_en', 'description_he'];
        }
        $remove = trim((string) ($operation['remove_text'] ?? ''));
        $set = is_array($operation['set'] ?? null) ? $operation['set'] : [];
        $next = $row;
        foreach ($fields as $field) {
            if (! is_string($field) || $remove === '') {
                continue;
            }
            $current = (string) ($next[$field] ?? '');
            if ($current === '' || ! str_contains($current, $remove)) {
                continue;
            }
            $next[$field] = $this->stripPhrase($current, $remove);
        }
        foreach ($set as $field => $value) {
            if (! is_string($field) || ! is_scalar($value)) {
                continue;
            }
            $next[$field] = $field === 'price'
                ? $this->normalizePrice((string) $value)
                : (string) $value;
        }

        if ($target === 'items') {
            $categoryId = (string) ($next['category_id'] ?? ($row['category_id'] ?? ($row['category']['id'] ?? '')));
            $body = [
                'name_ar' => (string) ($next['name_ar'] ?? ''),
                'name_en' => (string) ($next['name_en'] ?? ''),
                'name_he' => (string) ($next['name_he'] ?? ''),
                'description_ar' => (string) ($next['description_ar'] ?? ''),
                'description_en' => (string) ($next['description_en'] ?? ''),
                'description_he' => (string) ($next['description_he'] ?? ''),
                'price' => $this->normalizePrice((string) ($next['price'] ?? '0')),
                'category_id' => $categoryId,
                'status' => (string) ($next['status'] ?? $row['status'] ?? '1'),
            ];
            $sent = $this->sendKaman('PUT', '/items/'.$id, $body, $token, $baseUrl);
        } elseif ($target === 'categories') {
            $body = [
                'name_ar' => (string) ($next['name_ar'] ?? ''),
                'name_en' => (string) ($next['name_en'] ?? ''),
                'name_he' => (string) ($next['name_he'] ?? ''),
                'order_index' => (string) ($row['order_index'] ?? '0'),
            ];
            $sent = $this->sendKaman('PUT', '/categories/'.$id, $body, $token, $baseUrl);
        } elseif ($target === 'groups') {
            $body = [
                'name_ar' => (string) ($next['name_ar'] ?? ''),
                'name_en' => (string) ($next['name_en'] ?? ''),
                'name_he' => (string) ($next['name_he'] ?? ''),
                'type' => (string) ($next['type'] ?? $row['type'] ?? 'multi_option'),
                'max_options' => (int) ($next['max_options'] ?? $row['max_options'] ?? 2),
                'min_options' => (int) ($next['min_options'] ?? $row['min_options'] ?? 1),
                'order_index' => (int) ($row['order_index'] ?? 0),
                'must_pick' => filter_var($next['must_pick'] ?? $row['must_pick'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            $sent = $this->sendCashier('PUT', '/ingredient-categories/'.$id, $body, $token, $baseUrl);
        } else {
            $body = [
                'name_ar' => (string) ($next['name_ar'] ?? ''),
                'name_en' => (string) ($next['name_en'] ?? ''),
                'name_he' => (string) ($next['name_he'] ?? ''),
                'price' => (float) ($next['price'] ?? 0),
                'category_id' => (string) ($next['category_id'] ?? $row['category_id'] ?? ''),
                'allow_multiple' => (bool) ($row['allow_multiple'] ?? false),
                'order_index' => (int) ($row['order_index'] ?? 0),
            ];
            $sent = $this->sendCashier('PUT', '/ingredients/'.$id, $body, $token, $baseUrl);
        }

        if (! $sent['ok']) {
            return ['ok' => false, 'summary' => $label.' HTTP '.$sent['status'].' '.mb_substr($sent['content'], 0, 180)];
        }

        return ['ok' => true, 'summary' => $label.' → '.$this->rowLabel($next)];
    }

    private function stripPhrase(string $value, string $phrase): string
    {
        $value = str_replace($phrase, '', $value);
        $value = preg_replace('/\s*[،,]\s*(?=\s|$)/u', '', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
        $value = preg_replace('/^[\s،,]+|[\s،,]+$/u', '', $value) ?? $value;

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(string $token, string $baseUrl, string $path): array
    {
        return $this->collectPages(function (int $page) use ($token, $baseUrl, $path): array {
            $sent = $this->sendKaman('GET', $path.'?page='.$page.'&per_page=100', [], $token, $baseUrl);
            if (! $sent['ok'] && $page === 1) {
                $sent = $this->sendKaman('GET', $path, [], $token, $baseUrl);
            }
            if (! $sent['ok']) {
                return [];
            }

            return $this->unwrapList(json_decode($sent['content'], true));
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCashierPaged(string $token, string $baseUrl, string $path): array
    {
        return $this->collectPages(function (int $page) use ($token, $baseUrl, $path): array {
            $sent = $this->sendCashier('GET', $path.'?page='.$page.'&per_page=100', [], $token, $baseUrl);
            if (! $sent['ok']) {
                return [];
            }

            return $this->unwrapList(json_decode($sent['content'], true));
        });
    }

    /**
     * @param  callable(int): list<array<string, mixed>>  $load
     * @return list<array<string, mixed>>
     */
    private function collectPages(callable $load): array
    {
        $all = [];
        $seen = [];
        for ($page = 1; $page <= 20; $page++) {
            $batch = $load($page);
            if ($batch === []) {
                break;
            }
            $fresh = 0;
            foreach ($batch as $row) {
                $id = $this->rowId($row);
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }
                if ($id !== '') {
                    $seen[$id] = true;
                }
                $all[] = $row;
                $fresh++;
            }
            if ($fresh === 0 || count($batch) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unwrapList(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
            return array_values(array_filter($data['items'], 'is_array'));
        }
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['categories', 'items', 'ingredients'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, content: string, with_image: bool}
     */
    private function sendCashier(string $method, string $path, array $body, string $token, string $managerBase): array
    {
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            if (preg_match('/^[A-Za-z0-9=&_.\-]*$/', $queryString) === 1) {
                $query = '?'.$queryString;
            }
        }
        $normalized = $this->normalizePath($path);
        if ($normalized === null) {
            return [
                'ok' => false,
                'status' => 0,
                'content' => json_encode(['error' => 'Invalid cashier path'], JSON_UNESCAPED_UNICODE),
                'with_image' => false,
            ];
        }

        $url = rtrim(KamanUrl::cashierApiFromManager($managerBase), '/').$normalized.$query;

        return $this->dispatchKaman($method, $url, $token, $body, null);
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
                    'name' => 'import_menu_url',
                    'description' => 'Open a Wolt restaurant URL or a public Kaman menu URL and copy the full menu onto this restaurant, including item photos and option groups.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                                'description' => 'https://wolt.com/.../restaurant/... or a public https://name.kaman.rest/menu link',
                            ],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_menu',
                    'description' => 'Read the restaurant menu already stored on Kaman: categories, meals, and option groups with ids and names in Arabic, Hebrew, and English.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'edit_menu',
                    'description' => 'Change the live menu. Match rows by name, then remove a phrase and/or set fields. Does not create new meals.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'operations' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 40,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'target' => [
                                            'type' => 'string',
                                            'enum' => ['items', 'categories', 'choices', 'groups'],
                                            'description' => 'items = meals and drinks, categories = sections, choices = option values, groups = option questions',
                                        ],
                                        'match' => [
                                            'type' => 'string',
                                            'description' => 'Text that appears in the row name. Leave empty to match remove_text.',
                                        ],
                                        'remove_text' => [
                                            'type' => 'string',
                                            'description' => 'Phrase to delete from matching names, such as زجاجة زجاج.',
                                        ],
                                        'fields' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                            'description' => 'name_ar, name_en, name_he, description_ar, description_en, description_he, or price. Empty means every name field that contains remove_text.',
                                        ],
                                        'set' => [
                                            'type' => 'object',
                                            'description' => 'Replacement values. Only include fields that should change.',
                                            'properties' => [
                                                'name_ar' => ['type' => 'string'],
                                                'name_en' => ['type' => 'string'],
                                                'name_he' => ['type' => 'string'],
                                                'description_ar' => ['type' => 'string'],
                                                'description_en' => ['type' => 'string'],
                                                'description_he' => ['type' => 'string'],
                                                'price' => ['type' => 'string'],
                                                'category_id' => ['type' => 'string'],
                                                'status' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['target'],
                                ],
                            ],
                        ],
                        'required' => ['operations'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'assign_meal_photos',
                    'description' => 'Match meal photos already uploaded in this chat to menu meals. File names are tried first. Remaining photos are matched by looking at the food. Matched photos are uploaded onto the meals.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ],
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
            'import_menu_url' => $this->importMenuTool($arguments, $token, $baseUrl),
            'assign_meal_photos' => $this->assignMealPhotosTool($token, $baseUrl),
            'read_menu' => $this->readMenu($token, $baseUrl),
            'edit_menu' => $this->editMenu($arguments, $token, $baseUrl),
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
    private function sendKaman(string $method, string $path, array $body, string $token, string $baseUrl, ?string $imagePath = null, bool $retryWithoutImage = true): array
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

        $query = '';
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            if (preg_match('/^[A-Za-z0-9=&_.\-]*$/', $queryString) === 1) {
                $query = '?'.$queryString;
            }
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
            && $method === 'POST'
            && $normalized === '/items'
        ) {
            $imagePath = $this->drinks->imageFor([
                (string) ($body['name_en'] ?? ''),
                (string) ($body['name_ar'] ?? ''),
                (string) ($body['name_he'] ?? ''),
                (string) ($body['name'] ?? ''),
            ]);
        }

        $url = KamanUrl::join($baseUrl, $normalized).$query;
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

        if ($retryWithoutImage && $canAttachImage && ! $sent['ok'] && ! $this->looksLikeDuplicate($sent)) {
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
        if ($name === 'import_menu_url') {
            return ['url' => (string) ($arguments['url'] ?? '')];
        }
        if ($name === 'read_menu' || $name === 'assign_meal_photos') {
            return [];
        }
        if ($name === 'edit_menu') {
            $operations = $arguments['operations'] ?? [];

            return [
                'operations' => is_array($operations) ? count($operations) : 0,
            ];
        }

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
        $omitTemperature = false;
        $useCompletionTokens = false;
        $reasoningEffort = array_key_exists('reasoning_effort', $options)
            ? (($options['reasoning_effort'] === null || $options['reasoning_effort'] === '') ? null : (string) $options['reasoning_effort'])
            : (($tools !== null && $this->chatToolsNeedNoReasoning($model)) ? 'none' : null);

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
            ];
            if (! $omitTemperature) {
                $payload['temperature'] = $options['temperature'] ?? 0.2;
            }
            $tokenLimit = $options['max_tokens'] ?? 4096;
            if ($useCompletionTokens) {
                $payload['max_completion_tokens'] = $tokenLimit;
            } else {
                $payload['max_tokens'] = $tokenLimit;
            }
            if ($tools !== null) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }
            if ($reasoningEffort !== null) {
                $payload['reasoning_effort'] = $reasoningEffort;
            }
            $payload = $this->utf8Value($payload);

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
            if (! $useCompletionTokens && str_contains($errorText, 'max_tokens')) {
                $useCompletionTokens = true;
                $attempts--;
                continue;
            }
            if (! $omitTemperature && str_contains(strtolower($errorText), 'temperature')) {
                $omitTemperature = true;
                $attempts--;
                continue;
            }
            if ($reasoningEffort !== 'none' && str_contains($errorText, 'reasoning_effort')) {
                $reasoningEffort = 'none';
                $attempts--;
                continue;
            }
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

    private function chatToolsNeedNoReasoning(string $model): bool
    {
        return preg_match('/^gpt-(?:5|6)/i', $model) === 1;
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
        $value = $this->utf8String($value);
        if (strlen($value) <= $max) {
            return $value;
        }

        $slice = mb_strcut($value, 0, max(0, $max - 16), 'UTF-8');

        return $slice."\n…truncated…";
    }

    private function jsonForModel(mixed $value, int $maxBytes): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($this->utf8Value($value), $flags);
        if (! is_string($json) || $json === '') {
            return '{}';
        }
        if (strlen($json) <= $maxBytes) {
            return $json;
        }
        if (is_array($value) && isset($value['categories']) && is_array($value['categories'])) {
            return $this->fitMenuJson($value, $maxBytes, $flags);
        }

        return $this->truncate($json, $maxBytes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fitMenuJson(array $payload, int $maxBytes, int $flags): string
    {
        $encode = function (array $data) use ($flags): string {
            $json = json_encode($this->utf8Value($data), $flags);

            return is_string($json) && $json !== '' ? $json : '{}';
        };

        foreach ($payload['categories'] as &$category) {
            if (! is_array($category['items'] ?? null)) {
                continue;
            }
            foreach ($category['items'] as &$item) {
                if (! is_array($item)) {
                    continue;
                }
                unset($item['description_ar'], $item['description_en'], $item['description_he']);
            }
        }
        unset($category, $item);

        $payload['note'] = 'Descriptions omitted so this list fits. edit_menu still matches every live row by name, including rows left out of this list.';
        $json = $encode($payload);
        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        if (isset($payload['choices']) && is_array($payload['choices']) && count($payload['choices']) > 15) {
            $payload['choices_omitted'] = count($payload['choices']) - 15;
            $payload['choices'] = array_slice($payload['choices'], -15);
            $json = $encode($payload);
            if (strlen($json) <= $maxBytes) {
                return $json;
            }
        }

        $guard = 0;
        while (strlen($json) > $maxBytes && $guard < 800) {
            $guard++;
            $dropped = false;
            foreach ($payload['categories'] as &$category) {
                if (! is_array($category['items'] ?? null) || $category['items'] === []) {
                    continue;
                }
                array_shift($category['items']);
                $payload['items_omitted'] = (int) ($payload['items_omitted'] ?? 0) + 1;
                $dropped = true;
                $json = $encode($payload);
                if (strlen($json) <= $maxBytes) {
                    unset($category);

                    return $json;
                }
            }
            unset($category);
            if (! $dropped) {
                break;
            }
        }

        return strlen($json) <= $maxBytes ? $json : $this->truncate($json, $maxBytes);
    }

    private function utf8Value(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->utf8String($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->utf8String($key) : $key;
            $out[$cleanKey] = $this->utf8Value($item);
        }

        return $out;
    }

    private function utf8String(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            return '';
        }
        $decoded = json_decode($encoded, true);

        return is_string($decoded) ? $decoded : '';
    }
}
