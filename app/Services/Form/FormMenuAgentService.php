<?php

declare(strict_types=1);

namespace App\Services\Form;

use App\Exceptions\FormWorkflowPausedException;
use App\Services\Haat\KamanHttp;
use App\Services\Haat\KamanMenuWriter;
use App\Services\Haat\KamanPublicMenuScraper;
use App\Support\KamanUrl;
use App\Support\MenuNaming;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FormMenuAgentService
{
    public const MAX_TOOL_ITERATIONS = 24;

    public const MAX_BATCH_REQUESTS = 80;

    public const MAX_ATTACHMENTS = 50;

    public const MAX_OPTION_WRITES = 200;

    /** Pages read from one PDF menu. */
    public const MAX_PDF_PAGES = 12;

    private const TRANSLATION_RULES = <<<'RULES'
Translate each restaurant menu row into Arabic, Hebrew, and English. Return only JSON {"rows":[{"id":"","name_ar":"","name_en":"","name_he":"","description_ar":"","description_en":"","description_he":""}]}. Keep the source text in its own language. Arabic must use Arabic letters. Hebrew must use Hebrew letters. English must use Latin letters. Do not copy one language into the other fields. A brand written in Latin letters is spelled out in Hebrew and Arabic letters in those fields.
Translate the whole description, keep every number, price, and "|" separator, and do not add ingredients that are not written.
Use local restaurant usage: فقع = mushrooms / פטריות. جامبا = bell pepper, gamba / גמבה. تيرس = corn / תירס. بيريه = mashed potato / פירה. هيليون = asparagus / אספרגוס. شمينت = cream / שמנת. دينيس = sea bream / דניס. شريمبس = shrimp / שרימפס. فواكة البحر = seafood / פירות ים. زعتر on a risotto = thyme / טימין. غرام = g / גרם. ملم = ml / מ"ל. صغير = small / קטן. كبير = large / גדול.
If you are not sure what a word means, keep that word as written in Arabic inside the translation. Do not guess, and do not invent a meaning such as a wine name.
RULES;

    private const IMPORT_TEXTS = [
        'en' => [
            'verified' => 'The menu is built and verified on the public menu: %d categories, %d dishes, %d photos.',
            'not_verified' => 'The import is NOT complete. The public menu shows %d categories, %d of the dishes, %d photos.',
            'written' => '%d dishes created, %d already there and left as they were, %d updated.',
            'could_not' => 'The menu could not be imported. Nothing is confirmed on the public menu.',
            'splits' => 'Split into two dishes by size:',
            'conflict' => '%s: the description says %s, the price badge says %s. I used the badge, because that is what the site charges.',
            'extras' => '%d other dishes that were already on this menu are also showing.',
            'warning_no_price' => '%s has no price on the source, so it was skipped.',
            'warning_unnamed_prices' => '%s shows the prices %s but names no sizes. I used the first price.',
            'warning_no_photo' => 'No photo on the source',
            'problem_write_failed' => 'Could not be written',
            'problem_hidden' => 'Status is not 1 in the manager API',
            'problem_no_photo' => 'No photo in the manager API',
            'problem_no_public_page' => 'The public menu page could not be opened',
            'problem_no_chips' => 'The public menu shows no category chips',
            'problem_empty_state' => 'The public menu shows no products',
            'problem_not_public' => 'Not showing on the public menu',
            'problem_no_public_photo' => 'No photo on the public menu',
            'problem_wrong_price' => 'Wrong price on the public menu',
            'problem_wrong_category' => 'Wrong category on the public menu',
            'problem_broken_photo' => 'A sample photo from the public menu did not open as an image',
        ],
        'ar' => [
            'verified' => 'تم بناء القائمة والتحقق منها في القائمة العامة: %d فئات، %d أطباق، %d صور.',
            'not_verified' => 'لم يكتمل الاستيراد. القائمة العامة تعرض %d فئات و%d من الأطباق و%d صور.',
            'written' => 'تم إنشاء %d أطباق، و%d موجودة مسبقا ولم تتغير، وتم تحديث %d.',
            'could_not' => 'تعذر استيراد القائمة. لا يوجد شيء مؤكد في القائمة العامة.',
            'splits' => 'تم تقسيمها إلى طبقين حسب الحجم:',
            'conflict' => '%s: الوصف يذكر %s بينما شارة السعر تذكر %s. استخدمت شارة السعر لأن الموقع يحاسب عليها.',
            'extras' => 'تظهر أيضا %d أطباق أخرى كانت موجودة في هذه القائمة.',
            'warning_no_price' => '%s بدون سعر في المصدر، لذلك لم تتم إضافته.',
            'warning_unnamed_prices' => '%s يعرض الأسعار %s بدون أحجام. استخدمت السعر الأول.',
            'warning_no_photo' => 'بدون صورة في المصدر',
            'problem_write_failed' => 'تعذرت الكتابة',
            'problem_hidden' => 'الحالة ليست 1 في واجهة الإدارة',
            'problem_no_photo' => 'بدون صورة في واجهة الإدارة',
            'problem_no_public_page' => 'تعذر فتح صفحة القائمة العامة',
            'problem_no_chips' => 'القائمة العامة لا تعرض الفئات',
            'problem_empty_state' => 'القائمة العامة لا تعرض أي منتجات',
            'problem_not_public' => 'لا يظهر في القائمة العامة',
            'problem_no_public_photo' => 'بدون صورة في القائمة العامة',
            'problem_wrong_price' => 'سعر خاطئ في القائمة العامة',
            'problem_wrong_category' => 'فئة خاطئة في القائمة العامة',
            'problem_broken_photo' => 'صورة نموذجية من القائمة العامة لم تفتح كصورة',
        ],
        'he' => [
            'verified' => 'התפריט נבנה ואומת בתפריט הציבורי: %d קטגוריות, %d מנות, %d תמונות.',
            'not_verified' => 'הייבוא לא הושלם. התפריט הציבורי מציג %d קטגוריות, %d מהמנות, %d תמונות.',
            'written' => 'נוצרו %d מנות, %d כבר היו ונשארו כפי שהן, %d עודכנו.',
            'could_not' => 'לא ניתן היה לייבא את התפריט. שום דבר לא אומת בתפריט הציבורי.',
            'splits' => 'פוצל לשתי מנות לפי גודל:',
            'conflict' => '%s: בתיאור כתוב %s, בתג המחיר כתוב %s. השתמשתי בתג המחיר, כי זה מה שהאתר גובה.',
            'extras' => 'מוצגות גם %d מנות נוספות שכבר היו בתפריט הזה.',
            'warning_no_price' => 'ל-%s אין מחיר במקור, ולכן לא נוסף.',
            'warning_unnamed_prices' => '%s מציג את המחירים %s בלי גדלים. השתמשתי במחיר הראשון.',
            'warning_no_photo' => 'אין תמונה במקור',
            'problem_write_failed' => 'לא ניתן היה לכתוב',
            'problem_hidden' => 'הסטטוס אינו 1 בממשק הניהול',
            'problem_no_photo' => 'אין תמונה בממשק הניהול',
            'problem_no_public_page' => 'לא ניתן היה לפתוח את דף התפריט הציבורי',
            'problem_no_chips' => 'התפריט הציבורי לא מציג קטגוריות',
            'problem_empty_state' => 'התפריט הציבורי לא מציג מוצרים',
            'problem_not_public' => 'לא מופיע בתפריט הציבורי',
            'problem_no_public_photo' => 'אין תמונה בתפריט הציבורי',
            'problem_wrong_price' => 'מחיר שגוי בתפריט הציבורי',
            'problem_wrong_category' => 'קטגוריה שגויה בתפריט הציבורי',
            'problem_broken_photo' => 'תמונה לדוגמה מהתפריט הציבורי לא נפתחה כתמונה',
        ],
    ];

    /** @var callable(array<string, mixed>): void */
    private $emit;

    /** @var list<array{name: string, path: string, mime: string, kind?: string}> */
    private array $turnAttachments = [];

    private string $turnApiKey = '';

    private string $turnUserText = '';

    /** @var array<string, mixed>|null */
    private ?array $turnDraft = null;

    private bool $mealPhotosHandled = false;

    private string $mealPhotoReport = '';

    public function __construct(
        private readonly FormMenuDrinkCatalog $drinks,
        private readonly WoltMenuSource $wolt,
        private readonly WebsiteMenuSource $website,
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
        $this->turnUserText = $userText;
        $memory['has_new_menu_files'] = ! empty($memory['has_new_menu_files']) || $hasNewMenuFiles;
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

    /**
     * Wolt and public Kaman menus are always a source. Any other public https link is one only
     * when the same message asks to analyze, extract, build, copy, or import.
     */
    private function menuSourceUrl(string $text, ?bool $importIntent = null): ?string
    {
        if (preg_match_all('#https?://[^\s<>"\',]+#iu', $text, $matches) === 0) {
            return null;
        }
        $importIntent ??= $this->wantsMenuImport($text);
        foreach ($matches[0] as $candidate) {
            $url = $this->acceptedSourceUrl(rtrim($candidate, '.,);]'), $importIntent);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function acceptedSourceUrl(string $url, bool $importIntent): ?string
    {
        if (str_contains($url, '…') || str_ends_with($url, '...')) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($this->wolt->isWoltUrl($url)) {
            return preg_match('#/restaurant/[^/]+#', $path) === 1 ? $url : null;
        }
        if ($this->isKamanPublicMenuUrl($url)) {
            return $url;
        }
        // Other wolt.com pages and a restaurant's own Kaman login are not menu sources.
        if (str_ends_with($host, 'wolt.com') || preg_match('/(?:^|\.)kaman\.(?:rest|dev)$/', $host) === 1) {
            return null;
        }

        return $importIntent && $this->website->isAllowedUrl($url) ? $url : null;
    }

    private function wantsMenuImport(string $text): bool
    {
        return preg_match('/build|copy|paste|import|scrape|extract|analy[sz]e|from scratch|انسخ|حلل|ابن|استخرج|תעתיק|תבנה|העתק|העתקה|ייבא|חלץ/u', mb_strtolower($text)) === 1;
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

        $url = $this->locateMenuSource($userText, $history, $memory);
        // Menu files in this turn are the source, not a website mentioned next to them.
        if ($url !== null && ! empty($memory['has_new_menu_files']) && $this->isWebsiteSource($url)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $memory
     */
    private function locateMenuSource(string $userText, array $history, array $memory): ?string
    {
        $direct = $this->menuSourceUrl($userText);
        if ($direct !== null) {
            return $direct;
        }

        $fromHistory = $this->findMenuUrlInHistory($history);
        if ($fromHistory !== null) {
            return $fromHistory;
        }

        $stored = $this->menuSourceUrl((string) ($memory['source_url'] ?? ''), true);
        if ($stored !== null) {
            return $stored;
        }

        return $this->menuSourceUrl((string) ($memory['title'] ?? ''));
    }

    /**
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @param  array{categories?: list<array<string, mixed>>}|null  $draft
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array}
     */
    private function importLinkedMenu(string $url, string $token, string $baseUrl, callable $emit, array $attachments, ?array $draft): array
    {
        if ($this->isWebsiteSource($url)) {
            return $this->importWebsiteMenu($url, $token, $baseUrl, $emit, $attachments, $draft);
        }

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
            $check = $this->verifyPublicMenu($parsed, $report, $token, $baseUrl, $emit);
            foreach ($check['problems'] as $problem) {
                $reply .= "\n".'Public menu check: '.$this->importText('en', 'problem_'.$problem['code'])
                    .($problem['names'] === [] ? '' : ': '.implode(', ', array_slice($problem['names'], 0, 8)));
            }
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
     * @param  list<array{name: string, path: string, mime: string}>  $attachments
     * @param  array{categories?: list<array<string, mixed>>}|null  $draft
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, attachments: list<array{name: string, path: string, mime: string}>, menu_draft: ?array, failed: bool}
     */
    private function importWebsiteMenu(string $url, string $token, string $baseUrl, callable $emit, array $attachments, ?array $draft): array
    {
        $language = $this->replyLanguage($this->turnUserText);
        $failed = true;
        $emit([
            'event' => 'status',
            'message' => 'Opening the restaurant website…',
        ]);

        try {
            $parsed = $this->website->parse($url);
            if ($parsed['meals'] === []) {
                $parsed = $this->extractWebsiteFallback((string) ($parsed['page_text'] ?? ''), $emit);
            }
            if ($parsed['meals'] === []) {
                throw new \RuntimeException('No menu was found on that website.');
            }
            $emit([
                'event' => 'status',
                'message' => 'Found '.count($parsed['categories']).' categories and '.count($parsed['meals']).' dishes.',
            ]);

            $parsed = $this->fillEmptyDescriptions($this->translateMenu($parsed, $emit, true));
            $parsed['keep_existing'] = ! $this->wantsRebuild($this->turnUserText);

            $emit([
                'event' => 'status',
                'message' => 'Writing categories, dishes, and photos…',
            ]);
            $report = $this->writer->write($baseUrl, $token, $parsed, true, function (string $step, string $message) use ($emit): void {
                $emit([
                    'event' => 'status',
                    'message' => $message,
                ]);
            });

            $check = $this->verifyPublicMenu($parsed, $report, $token, $baseUrl, $emit);
            $failed = ! $check['ok'];
            $reply = $this->websiteReply($language, $parsed, $report, $check);
        } catch (FormWorkflowPausedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Website menu import failed', ['url' => $url, 'error' => $e->getMessage()]);
            $reply = $this->importText($language, 'could_not').' '.$e->getMessage();
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
                'ok' => ! $failed,
                'summary' => $reply,
            ]],
            'attachments' => $attachments,
            'menu_draft' => $draft,
            'failed' => $failed,
        ];
    }

    /**
     * The writer's report is not proof. The manager API and the page a customer opens are.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $report
     * @return array{ok: bool, problems: list<array{code: string, names: list<string>}>, categories: int, dishes: int, photos: int, extras: int, missing_source_photos: list<string>}
     */
    private function verifyPublicMenu(array $parsed, array $report, string $token, string $baseUrl, callable $emit): array
    {
        $emit([
            'event' => 'status',
            'message' => 'Checking the public menu…',
        ]);

        $itemIds = [];
        $created = [];
        foreach (['created', 'updated', 'reused'] as $bucket) {
            foreach ($report['meals'][$bucket] ?? [] as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
                    $itemIds[(string) ($row['haat_id'] ?? '')] = (string) $row['id'];
                    if ($bucket === 'created') {
                        $created[(string) ($row['haat_id'] ?? '')] = true;
                    }
                }
            }
        }
        $categoryIds = [];
        foreach (['created', 'updated', 'reused'] as $bucket) {
            foreach ($report['categories'][$bucket] ?? [] as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
                    $categoryIds[(string) ($row['haat_id'] ?? '')] = (string) $row['id'];
                }
            }
        }

        $problems = [];
        $failedRows = array_merge($report['meals']['failed'] ?? [], $report['categories']['failed'] ?? []);
        if ($failedRows !== []) {
            $problems['write_failed'] = array_map(static fn (array $row): string => (string) ($row['name'] ?? $row['haat_id'] ?? ''), $failedRows);
        }

        $meals = [];
        $missingPhotos = [];
        foreach ($parsed['meals'] ?? [] as $meal) {
            if (! is_array($meal) || ! ($meal['is_available'] ?? true) || ! isset($itemIds[(string) ($meal['haat_id'] ?? '')])) {
                continue;
            }
            $meals[] = $meal;
            if (! $this->mealHasSourcePhoto($meal)) {
                $missingPhotos[] = $this->mealLabel($meal);
            }
        }

        $this->checkManagerItems($meals, $itemIds, $token, $baseUrl, $problems);

        $public = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $found = $this->checkPublicPage($meals, $itemIds, $categoryIds, $created, $baseUrl);
            $public = $found;
            if ($found['problems'] === [] || app()->environment('testing')) {
                break;
            }
            sleep(5);
        }
        $problems = array_merge($problems, $public['problems']);

        $out = [];
        foreach ($problems as $code => $names) {
            $out[] = ['code' => (string) $code, 'names' => array_values($names)];
        }

        return [
            'ok' => $out === [],
            'problems' => $out,
            'categories' => $public['categories'],
            'dishes' => $public['dishes'],
            'photos' => $public['photos'],
            'extras' => $public['extras'],
            'missing_source_photos' => $missingPhotos,
        ];
    }

    /**
     * GET /items must show status 1 and a photo. A dish that does not gets one repair attempt.
     *
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @param  array<string, list<string>>  $problems
     */
    private function checkManagerItems(array $meals, array $itemIds, string $token, string $baseUrl, array &$problems): void
    {
        $rows = $this->itemsById($baseUrl, $token);
        $repaired = false;
        foreach ($meals as $meal) {
            $row = $rows[$itemIds[(string) $meal['haat_id']]] ?? null;
            if ($row === null) {
                continue;
            }
            $hidden = ! $this->itemIsLive($row);
            $noPhoto = $this->mealHasSourcePhoto($meal) && ! $this->itemHasPhoto($row);
            if ($hidden || $noPhoto) {
                $this->writer->repairMeal($baseUrl, $token, $row, $meal, $noPhoto);
                $repaired = true;
            }
        }
        if ($repaired) {
            $rows = $this->itemsById($baseUrl, $token);
        }
        foreach ($meals as $meal) {
            $row = $rows[$itemIds[(string) $meal['haat_id']]] ?? null;
            if ($row === null) {
                continue;
            }
            if (! $this->itemIsLive($row)) {
                $problems['hidden'][] = $this->mealLabel($meal);
            }
            if ($this->mealHasSourcePhoto($meal) && ! $this->itemHasPhoto($row)) {
                $problems['no_photo'][] = $this->mealLabel($meal);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $meals
     * @param  array<string, string>  $itemIds
     * @param  array<string, string>  $categoryIds
     * @param  array<string, bool>  $created
     * @return array{problems: array<string, list<string>>, categories: int, dishes: int, photos: int, extras: int}
     */
    private function checkPublicPage(array $meals, array $itemIds, array $categoryIds, array $created, string $baseUrl): array
    {
        $problems = [];
        $result = ['categories' => 0, 'dishes' => 0, 'photos' => 0, 'extras' => 0];

        $http = Http::timeout(60)->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; KamanMenuClone/1.0)']);
        if (! config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }
        try {
            $response = $http->get(KamanUrl::originFromManagerApi($baseUrl).'/menu');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['problems' => ['no_public_page' => []]] + $result;
        }
        if (! $response->successful()) {
            return ['problems' => ['no_public_page' => []]] + $result;
        }

        $page = $this->publicMenus->inspect($response->body());
        if ($page['chips'] === []) {
            $problems['no_chips'] = [];
        }
        if ($page['empty_visible'] || $page['dishes'] === []) {
            $problems['empty_state'] = [];
        }

        $byName = [];
        foreach ($page['dishes'] as $id => $dish) {
            $byName[$dish['name']] ??= (string) $id;
        }

        $found = [];
        $wantedChips = [];
        $photoSample = '';
        $photos = 0;
        foreach ($meals as $meal) {
            $haatId = (string) $meal['haat_id'];
            $id = $itemIds[$haatId];
            if (! isset($page['dishes'][$id])) {
                foreach ($this->mealNames($meal) as $name) {
                    if (isset($byName[$name])) {
                        $id = $byName[$name];
                        break;
                    }
                }
            }
            $dish = $page['dishes'][$id] ?? null;
            if ($dish === null) {
                $problems['not_public'][] = $this->mealLabel($meal);

                continue;
            }
            $found[$id] = true;
            if ($dish['image'] !== '') {
                $photos++;
                $photoSample = $photoSample !== '' ? $photoSample : $dish['image'];
            } elseif ($this->mealHasSourcePhoto($meal)) {
                $problems['no_public_photo'][] = $this->mealLabel($meal);
            }
            if (isset($created[$haatId])) {
                if (abs((float) $dish['price'] - (float) $meal['price']) > 0.001) {
                    $problems['wrong_price'][] = $this->mealLabel($meal);
                }
                $wanted = $categoryIds[(string) ($meal['category_haat_id'] ?? '')] ?? '';
                if ($wanted !== '' && $dish['category'] !== '' && $dish['category'] !== $wanted) {
                    $problems['wrong_category'][] = $this->mealLabel($meal);
                }
                if ($wanted !== '') {
                    $wantedChips[$wanted] = true;
                }
            }
        }
        if (array_diff(array_keys($wantedChips), $page['chips']) !== []) {
            $problems['no_chips'] = [];
        }

        if ($photoSample !== '' && ! $this->isImageUrl($photoSample)) {
            $problems['broken_photo'] = [];
        }

        return [
            'problems' => $problems,
            'categories' => count($page['chips']),
            'dishes' => count($found),
            'photos' => $photos,
            'extras' => max(0, count($page['dishes']) - count($found)),
        ];
    }

    private function isImageUrl(string $url): bool
    {
        $http = Http::timeout(30);
        if (! config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }
        try {
            $response = $http->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return false;
        }

        return $response->successful() && str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsById(string $baseUrl, string $token): array
    {
        $rows = [];
        foreach ($this->writer->listItems($baseUrl, $token) as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
                $rows[(string) $row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function itemIsLive(array $row): bool
    {
        if (! array_key_exists('status', $row)) {
            return true;
        }
        $status = $row['status'];

        return $status === true || in_array(strtolower(trim((string) $status)), ['1', 'true', 'active'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function itemHasPhoto(array $row): bool
    {
        return trim((string) ($row['image_url'] ?? $row['image'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $meal
     */
    private function mealHasSourcePhoto(array $meal): bool
    {
        return is_string($meal['image_url'] ?? null) && $meal['image_url'] !== '';
    }

    /**
     * @param  array<string, mixed>  $meal
     */
    private function mealLabel(array $meal): string
    {
        return (string) ($meal['source_name'] ?? $meal['name_ar'] ?? $meal['name_en'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $meal
     * @return list<string>
     */
    private function mealNames(array $meal): array
    {
        return array_values(array_filter(array_map(
            static fn (string $field): string => trim((string) ($meal[$field] ?? '')),
            ['name_ar', 'name_en', 'name_he'],
        )));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function fillEmptyDescriptions(array $parsed): array
    {
        foreach ($parsed['meals'] ?? [] as $index => $meal) {
            foreach (['ar', 'en', 'he'] as $code) {
                if (trim((string) ($meal['description_'.$code] ?? '')) === '') {
                    $parsed['meals'][$index]['description_'.$code] = (string) ($meal['name_'.$code] ?? '');
                }
            }
        }

        return $parsed;
    }

    /**
     * Last resort for a page with no repeated cards. The model reads text; it is never the parser
     * for a page that has stable markup.
     *
     * @return array<string, mixed>
     */
    private function extractWebsiteFallback(string $pageText, callable $emit): array
    {
        if (trim($pageText) === '') {
            return ['categories' => [], 'meals' => []];
        }
        $emit([
            'event' => 'status',
            'message' => 'This website has no regular menu layout. Reading its text…',
        ]);

        $choice = $this->chatCompletions(trim((string) config('openai.api_key')), [
            ['role' => 'system', 'content' => 'You read the text of a restaurant website and return its menu as JSON: {"categories":[{"name":"","items":[{"name":"","description":"","price":"","image_url":""}]}]}. Copy names and descriptions exactly as written, in their original language. price is the selling price only, as written; when a dish shows several prices keep them separated by " | ". Never take a price from a description. image_url is the [image: url] directly next to the dish, or empty. Never invent a dish, a price, or an image. If the page has no menu, return {"categories":[]}.'],
            ['role' => 'user', 'content' => $pageText],
        ], null, [
            'temperature' => 0,
            'max_tokens' => 8000,
            'reasoning_effort' => 'low',
        ]);

        $content = trim((string) ($choice['content'] ?? ''));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        $decoded = $this->decodeMaybeJson($content);
        $categories = is_array($decoded) && is_array($decoded['categories'] ?? null) ? $decoded['categories'] : [];

        return $this->website->fromExtractedRows($categories, $pageText);
    }

    private function wantsRebuild(string $text): bool
    {
        return $this->wantsMenuRepair($text)
            || preg_match('/rebuild|re-build|from scratch|overwrite|إعادة بناء|اعادة بناء|من جديد|מחדש/u', mb_strtolower($text)) === 1;
    }

    private function replyLanguage(string $text): string
    {
        $text = preg_replace('#https?://\S+|\S+@\S+|\S+\.kaman\.\S+#iu', ' ', $text) ?? $text;
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return 'he';
        }

        return preg_match('/\p{Arabic}/u', $text) === 1 ? 'ar' : 'en';
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $check
     */
    private function websiteReply(string $language, array $parsed, array $report, array $check): string
    {
        $lines = [$check['ok']
            ? $this->importText($language, 'verified', $check['categories'], $check['dishes'], $check['photos'])
            : $this->importText($language, 'not_verified', $check['categories'], $check['dishes'], $check['photos'])];
        $lines[] = $this->importText(
            $language,
            'written',
            count($report['meals']['created'] ?? []),
            count($report['meals']['reused'] ?? []),
            count($report['meals']['updated'] ?? []),
        );

        foreach ($check['problems'] as $problem) {
            $names = array_slice($problem['names'], 0, 8);
            $lines[] = '• '.$this->importText($language, 'problem_'.$problem['code']).($names === [] ? '' : ': '.implode('، ', $names));
        }

        $counts = [];
        foreach ($parsed['meals'] ?? [] as $meal) {
            $counts[(string) ($meal['category_haat_id'] ?? '')] = ($counts[(string) ($meal['category_haat_id'] ?? '')] ?? 0) + 1;
        }
        $lines[] = '';
        foreach ($parsed['categories'] ?? [] as $category) {
            $lines[] = trim((string) ($category['name_'.$language] ?? $category['source_name'] ?? '')).': '.($counts[(string) $category['haat_id']] ?? 0);
        }

        if (($parsed['size_splits'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = $this->importText($language, 'splits');
            foreach ($parsed['size_splits'] as $split) {
                $sizes = array_map(static fn (array $size): string => $size['label'].' '.$size['price'], $split['sizes']);
                $lines[] = '• '.$split['name'].': '.implode(' | ', $sizes);
            }
        }

        if (($parsed['price_conflicts'] ?? []) !== []) {
            $lines[] = '';
            foreach ($parsed['price_conflicts'] as $conflict) {
                $lines[] = '• '.$this->importText(
                    $language,
                    'conflict',
                    $conflict['name'],
                    implode(' / ', $conflict['description_prices']),
                    implode(' / ', $conflict['badge_prices']),
                );
            }
        }

        foreach ($parsed['warnings'] ?? [] as $warning) {
            if (in_array($warning['type'] ?? '', ['no_price', 'unnamed_prices'], true)) {
                $lines[] = '• '.$this->importText($language, 'warning_'.$warning['type'], (string) $warning['name'], implode(' | ', $warning['prices'] ?? []));
            }
        }
        if (($check['missing_source_photos'] ?? []) !== []) {
            $lines[] = '• '.$this->importText($language, 'warning_no_photo').': '.implode('، ', array_slice($check['missing_source_photos'], 0, 8));
        }
        if (($check['extras'] ?? 0) > 0) {
            $lines[] = '• '.$this->importText($language, 'extras', $check['extras']);
        }

        return trim(implode("\n", $lines));
    }

    private function importText(string $language, string $key, mixed ...$args): string
    {
        $texts = self::IMPORT_TEXTS[$language] ?? self::IMPORT_TEXTS['en'];

        return vsprintf($texts[$key] ?? self::IMPORT_TEXTS['en'][$key] ?? $key, $args);
    }

    private function isWebsiteSource(string $url): bool
    {
        return ! $this->wolt->isWoltUrl($url) && ! $this->isKamanPublicMenuUrl($url);
    }

    private function isKamanPublicMenuUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'kaman.') && str_contains((string) parse_url($url, PHP_URL_PATH), '/menu');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function importMenuTool(array $arguments, string $token, string $baseUrl): array
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        if ($this->menuSourceUrl($url, $this->wantsLinkedMenuWork($this->turnUserText)) === null) {
            return [
                'ok' => false,
                'summary' => 'URL is not a Wolt restaurant, a public Kaman menu, or a public restaurant website',
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
    private function translateMenu(array $parsed, callable $emit, bool $strict = false): array
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
            $translated += $this->translateMenuChunk($chunk, $strict);
            $missing = array_values(array_filter($chunk, fn (array $row): bool => ! isset($translated[$row['id']])));
            if ($missing !== []) {
                $translated += $this->translateMenuChunk($missing, $strict);
            }
        }
        if ($strict) {
            // Smaller and smaller batches. A row that still fails stops the job before any write.
            foreach ([4, 1] as $size) {
                $missing = array_values(array_filter($rows, fn (array $row): bool => ! isset($translated[$row['id']])));
                foreach (array_chunk($missing, $size) as $chunk) {
                    $translated += $this->translateMenuChunk($chunk, true);
                }
            }
            $missing = array_values(array_filter($rows, fn (array $row): bool => ! isset($translated[$row['id']])));
            if ($missing !== []) {
                $names = array_map(static fn (array $row): string => (string) $row['name'], array_slice($missing, 0, 8));
                throw new \RuntimeException('Translation failed for '.count($missing).' rows, so nothing was written: '.implode(', ', $names));
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
    private function translateMenuChunk(array $rows, bool $strict = false): array
    {
        if ($rows === []) {
            return [];
        }

        try {
            $choice = $this->chatCompletions(trim((string) config('openai.api_key')), [
                ['role' => 'system', 'content' => self::TRANSLATION_RULES."\n".MenuNaming::guide()],
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
            if (! $this->translationAccepted((string) $source['name'], $row)
                || ! $this->descriptionAccepted((string) $source['description'], $row, $strict)) {
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
        if ($this->stillInWrongScript($he, '\p{Hebrew}')
            || $this->stillInWrongScript($en, '[A-Za-z]')
            || preg_match('/\p{Hebrew}/u', $en) === 1
            || ! $this->keepsNumbers($source, [$en, $he])) {
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
    private function descriptionAccepted(string $source, array $row, bool $strict): bool
    {
        if ($source === '') {
            return true;
        }
        $en = trim((string) ($row['description_en'] ?? ''));
        $he = trim((string) ($row['description_he'] ?? ''));
        if ($en === '' || $he === '') {
            return ! $strict;
        }

        return ! $this->stillInWrongScript($he, '\p{Hebrew}')
            && ! $this->stillInWrongScript($en, '[A-Za-z]')
            && $this->keepsNumbers($source, [$en, $he]);
    }

    /**
     * Arabic left in a Hebrew or English field. One uncertain word inside a translated
     * sentence is allowed; a field that is mostly Arabic is not a translation.
     */
    private function stillInWrongScript(string $value, string $wanted): bool
    {
        $arabic = preg_match_all('/\p{Arabic}/u', $value);
        if ($arabic === 0) {
            return false;
        }

        return $arabic > preg_match_all('/'.$wanted.'/u', $value);
    }

    /**
     * @param  list<string>  $translations
     */
    private function keepsNumbers(string $source, array $translations): bool
    {
        preg_match_all('/[0-9]+/', $source, $numbers);
        foreach ($translations as $translation) {
            preg_match_all('/[0-9]+/', $translation, $kept);
            if (array_diff($numbers[0], $kept[0]) !== []) {
                return false;
            }
        }

        return true;
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
                // The field the source was written in keeps the source wording.
                if (in_array($field, $row['source_fields'] ?? [], true) && trim((string) ($row[$field] ?? '')) !== '') {
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
                $uploadPath = (string) $photo['path'];
                if (! $this->isVisionReady($uploadPath) || $this->needsUpright($uploadPath)) {
                    // HEIC / BMP / sideways phone photos: Kaman gets an upright JPEG.
                    $uploadPath = $this->prepareVisionImage($uploadPath);
                }
                $sent = $this->uploadMealPhoto($candidate, $uploadPath, $token, $baseUrl);
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
            ."Ignore handwriting that is crossed out, and do not invent meals that are not on the page.\n"
            .MenuNaming::guide()."\n"
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
                    'status' => ($meal['is_available'] ?? true) ? '1' : '0',
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

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            // Spreadsheets and documents: read the text inside instead of skipping the file.
            $document = $this->documentText($path, $ext, $mime);
            if ($document !== null) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => $document === ''
                        ? "File {$name} has no readable text."
                        : "File {$name}:\n".$this->truncate($document, 60000),
                ];
                continue;
            }

            if (str_contains($mime, 'json') || str_starts_with($mime, 'text/')
                || in_array($ext, ['json', 'txt', 'csv', 'tsv', 'md', 'xml', 'html', 'htm'], true)) {
                $raw = $this->utf8String((string) file_get_contents($path));
                if (in_array($ext, ['html', 'htm'], true) || str_contains($mime, 'html')) {
                    $raw = $this->htmlToText($raw);
                }
                $blocks[] = [
                    'type' => 'text',
                    'text' => "File {$name}:\n".$this->truncate($raw, 60000),
                ];
                continue;
            }

            if ($mime === 'application/pdf' || $ext === 'pdf') {
                $pages = $this->pdfPagesToImages($path);
                $pdfText = $this->pdfText($path);
                if ($pdfText !== '') {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => "Text layer of PDF {$name} (use it to check spelling and prices):\n".$this->truncate($pdfText, 40000),
                    ];
                }
                if ($pages === [] && $pdfText === '') {
                    $blocks[] = [
                        'type' => 'text',
                        'text' => "PDF {$name} could not be read on this server. Ask for photos or screenshots of the pages.",
                    ];
                }
                foreach ($pages as $page) {
                    $imageCount++;
                    if ($imageCount > 30) {
                        break;
                    }
                    $blocks[] = $this->toVisionInput($this->prepareVisionImage($page));
                }
                continue;
            }

            if (! str_starts_with($mime, 'image/') && ! $this->looksLikeImage($path)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => "File {$name} is not a type I can read. Send photos, PDF, Excel, Word, CSV or text.",
                ];
                continue;
            }

            $imageCount++;
            if ($imageCount > 30) {
                continue;
            }

            $prepared = $this->prepareVisionImage($path);
            if (! $this->isVisionReady($prepared)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => "Image {$name} is in a format this server cannot convert (".($ext ?: $mime)."). Ask for a JPG or PNG.",
                ];
                continue;
            }
            $blocks[] = $this->toVisionInput($prepared);
        }

        return $blocks;
    }

    /**
     * Text from Excel / Word / OpenDocument / PowerPoint files, or null when the file is not one of them.
     */
    private function documentText(string $path, string $ext, string $mime): ?string
    {
        $kind = match (true) {
            $ext === 'xlsx' || str_contains($mime, 'spreadsheetml') => 'xlsx',
            $ext === 'docx' || str_contains($mime, 'wordprocessingml') => 'docx',
            $ext === 'pptx' || str_contains($mime, 'presentationml') => 'pptx',
            in_array($ext, ['ods', 'odt', 'odp'], true) || str_contains($mime, 'opendocument') => 'odf',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        try {
            return match ($kind) {
                'xlsx' => $this->xlsxText($zip),
                'docx' => $this->xmlText((string) $zip->getFromName('word/document.xml'), ['</w:p>' => "\n", '<w:tab/>' => "\t", '<w:br/>' => "\n"]),
                'pptx' => $this->pptxText($zip),
                default => $this->xmlText((string) $zip->getFromName('content.xml'), [
                    '</text:p>' => "\n",
                    '</text:h>' => "\n",
                    '</table:table-cell>' => "\t",
                    '</table:table-row>' => "\n",
                    '<text:tab/>' => "\t",
                ]),
            };
        } catch (\Throwable $e) {
            Log::warning('Menu agent could not read document', ['error' => $e->getMessage()]);

            return '';
        } finally {
            $zip->close();
        }
    }

    /**
     * Every sheet as tab-separated rows, so prices stay next to their names.
     */
    private function xlsxText(\ZipArchive $zip): string
    {
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $doc = @simplexml_load_string($sharedXml);
            if ($doc !== false) {
                foreach ($doc->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    }
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                    $shared[] = $text;
                }
            }
        }

        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $entry, $m) === 1) {
                $sheets[(int) $m[1]] = $entry;
            }
        }
        ksort($sheets);

        $out = [];
        foreach ($sheets as $number => $entry) {
            $doc = @simplexml_load_string((string) $zip->getFromName($entry));
            if ($doc === false || ! isset($doc->sheetData)) {
                continue;
            }
            $rows = [];
            foreach ($doc->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $type = (string) $cell['t'];
                    $value = $type === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v;
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    }
                    $cells[] = trim($value);
                }
                if (array_filter($cells, static fn (string $v): bool => $v !== '') !== []) {
                    $rows[] = implode("\t", $cells);
                }
            }
            if ($rows !== []) {
                $out[] = 'Sheet '.$number.":\n".implode("\n", $rows);
            }
        }

        return implode("\n\n", $out);
    }

    private function pptxText(\ZipArchive $zip): string
    {
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $entry, $m) === 1) {
                $slides[(int) $m[1]] = $this->xmlText((string) $zip->getFromName($entry), ['</a:p>' => "\n"]);
            }
        }
        ksort($slides);

        return trim(implode("\n\n", array_filter($slides)));
    }

    /**
     * @param  array<string, string>  $breaks  closing tags that become separators
     */
    private function xmlText(string $xml, array $breaks): string
    {
        if ($xml === '') {
            return '';
        }
        $xml = str_replace(array_keys($breaks), array_values($breaks), $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n+/u", "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The PDF text layer when the server has pdftotext. Scanned PDFs have none; their pages go in as images.
     */
    private function pdfText(string $path): string
    {
        if (! function_exists('shell_exec') || ! function_exists('escapeshellarg')) {
            return '';
        }
        $binary = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where '.$binary.' 2>NUL' : 'command -v '.$binary.' 2>/dev/null';
        $found = trim((string) @shell_exec($finder));
        if ($found === '') {
            return '';
        }
        $text = (string) @shell_exec($binary.' -layout -enc UTF-8 -l '.self::MAX_PDF_PAGES.' '.escapeshellarg($path).' - 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));

        return trim($this->utf8String($text));
    }

    /**
     * OpenAI vision takes JPEG, PNG, WEBP and GIF.
     */
    private function isVisionReady(string $path): bool
    {
        $mime = strtolower((string) (@mime_content_type($path) ?: ''));

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    private function looksLikeImage(string $path): bool
    {
        $mime = strtolower((string) (mime_content_type($path) ?: ''));

        return str_starts_with($mime, 'image/');
    }

    /**
     * A JPEG/PNG/WEBP the vision model accepts: iPhone HEIC, AVIF, BMP, TIFF and GIF are converted,
     * sideways phone photos are turned upright, and big photos are scaled to 1024px.
     */
    private function prepareVisionImage(string $path): string
    {
        $info = @getimagesize($path);
        if (! is_array($info)) {
            // HEIC / TIFF and anything GD cannot open: Imagick when the server has it.
            return $this->convertWithImagick($path) ?? $path;
        }
        if (! function_exists('imagecreatetruecolor')) {
            return $path;
        }
        [$width, $height, $type] = $info;
        $max = 1024;
        $bytes = (int) (@filesize($path) ?: 0);
        $orientation = $this->exifOrientation($path, $type);
        $native = in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
        if ($native && $orientation === 1 && $width <= $max && $height <= $max && $bytes > 0 && $bytes < 400_000) {
            return $path;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default => (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF && function_exists('imagecreatefromavif'))
                ? @imagecreatefromavif($path)
                : false,
        };
        if ($src === false) {
            return $this->convertWithImagick($path) ?? $path;
        }

        $src = match ($orientation) {
            3 => imagerotate($src, 180, 0) ?: $src,
            6 => imagerotate($src, -90, 0) ?: $src,
            8 => imagerotate($src, 90, 0) ?: $src,
            default => $src,
        };
        $width = imagesx($src);
        $height = imagesy($src);

        $scale = min($max / max(1, $width), $max / max(1, $height), 1);
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);

            return $path;
        }
        // Transparent PNG/GIF menus would turn black in JPEG; paint white underneath.
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        $temp = $this->visionTempPath('jpg');
        imagejpeg($dst, $temp, 80);
        imagedestroy($src);
        imagedestroy($dst);

        return is_file($temp) ? $temp : $path;
    }

    private function needsUpright(string $path): bool
    {
        $info = @getimagesize($path);

        return is_array($info) && $this->exifOrientation($path, (int) $info[2]) !== 1;
    }

    private function exifOrientation(string $path, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return in_array($orientation, [3, 6, 8], true) ? $orientation : 1;
    }

    private function convertWithImagick(string $path): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $imagick = new \Imagick;
            $imagick->readImage($path.'[0]');
            if (method_exists($imagick, 'autoOrient')) {
                $imagick->autoOrient();
            }
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->thumbnailImage(1024, 1024, true);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(80);
            $temp = $this->visionTempPath('jpg');
            $imagick->writeImage($temp);
            $imagick->clear();

            return is_file($temp) ? $temp : null;
        } catch (\Throwable $e) {
            Log::info('Menu agent could not convert image', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function visionTempPath(string $extension): string
    {
        $dir = storage_path('app/menu-agent-temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.Str::uuid().'.'.$extension;
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

    /**
     * Every page of a PDF menu (up to MAX_PDF_PAGES) as images: Imagick first, then pdftoppm.
     *
     * @return list<string>
     */
    private function pdfPagesToImages(string $path): array
    {
        $pages = [];
        if (class_exists(\Imagick::class)) {
            try {
                $probe = new \Imagick;
                $probe->pingImage($path);
                $count = min(max(1, $probe->getNumberImages()), self::MAX_PDF_PAGES);
                $probe->clear();
                for ($page = 0; $page < $count; $page++) {
                    $imagick = new \Imagick;
                    $imagick->setResolution(150, 150);
                    $imagick->readImage($path.'['.$page.']');
                    $imagick->setImageBackgroundColor('white');
                    $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                    $imagick->setImageFormat('png');
                    $temp = $this->visionTempPath('png');
                    $imagick->writeImage($temp);
                    $imagick->clear();
                    if (is_file($temp)) {
                        $pages[] = $temp;
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Menu agent Imagick PDF read failed', ['error' => $e->getMessage()]);
            }
        }
        if ($pages !== [] || ! function_exists('shell_exec')) {
            return $pages;
        }

        // Shared hosts often have poppler without the Imagick PHP extension.
        $binary = PHP_OS_FAMILY === 'Windows' ? 'pdftoppm.exe' : 'pdftoppm';
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where '.$binary.' 2>NUL' : 'command -v '.$binary.' 2>/dev/null';
        if (trim((string) @shell_exec($finder)) === '') {
            return [];
        }
        $prefix = substr($this->visionTempPath('pdf'), 0, -4);
        @shell_exec($binary.' -r 150 -png -l '.self::MAX_PDF_PAGES.' '.escapeshellarg($path).' '.escapeshellarg($prefix));
        $found = glob($prefix.'-*.png') ?: [];
        natsort($found);

        return array_values($found);
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
        $naming = MenuNaming::guide();

        return <<<PROMPT
You are the restaurant menu operator on the Kaman Form page. Talk and act like Cursor: read the files, then send HTTP yourself. Hands-free. No WhatsApp.

Logged in:
- Base: {$baseUrl}
- Dashboard: {$host}

{$fileLine}
{$draftLine}

Use tools to patch, rename, create leftovers, or delete duplicates. Do not stop after a sample. If the job is to store a menu, every meal from every photo must be written — except names that already exist.

Tools:
- import_menu_url: open a Wolt restaurant link, a public Kaman /menu link, or any public https restaurant website and copy the whole menu, including photos and prices. Never say you cannot open the page.
- assign_meal_photos: match uploaded meal photos to meals by file name, then by looking at each photo, and upload the image onto the meal.
- read_menu: the live menu (categories, meals, and option groups). Call this before any change.
- edit_menu: apply chat edits to the live menu. Use this for rename, remove a phrase, change a price, change a description, or the same edit on many rows. Do not recreate meals to rename them.
- read_item_options: which option values (ingredients, add-ons) a meal has, with group, default, and extra price.
- edit_item_options: link or unlink option values on a meal or a whole menu section, and turn "selected by default" or "extra price" on or off.
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

Meal options (ingredients / add-ons):
- "add cheese to the pizza", "remove onion from the burger", "make ketchup default", "cheese should cost 3", "make sauces free" are option edits. Use edit_item_options, not kaman_request.
- link adds an option to a meal. unlink removes it from that meal only; the option itself stays in the menu.
- default true/false switches "selected by default". extra_fee false makes it free; custom_fee sets the extra price.
- Use group to link every value of an option group at once, and menu_category to apply to every meal in a section.
- To change the base price of an option value everywhere, use edit_menu with target "choices" and set price.
- If you are not sure which meal or option the user means, call read_item_options first.
- When edit_item_options reports a failure, say it plainly with the HTTP status. Do not claim it worked.

{$naming}

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
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function readItemOptions(array $arguments, string $token, string $baseUrl): array
    {
        $needle = trim((string) ($arguments['item'] ?? ''));
        $items = $this->matchByNameOrId($this->fetchPaged($token, $baseUrl, '/items'), $needle);
        if ($items === []) {
            return [
                'ok' => false,
                'summary' => 'No meal matched '.$needle,
                'content' => json_encode(['error' => 'No meal matched '.$needle], JSON_UNESCAPED_UNICODE),
            ];
        }

        $choices = $this->rowsById($this->fetchCashierPaged($token, $baseUrl, '/ingredients'));
        $groups = $this->rowsById($this->fetchCashierPaged($token, $baseUrl, '/ingredient-categories'));
        $out = [];
        $linked = 0;
        foreach (array_slice($items, 0, 10) as $item) {
            $itemId = $this->rowId($item);
            $options = [];
            foreach ($this->itemOptionLinks($itemId, $token, $baseUrl) as $ingredientId => $link) {
                $choice = $choices[$ingredientId] ?? (is_array($link['row']['ingredient'] ?? null) ? $link['row']['ingredient'] : []);
                $group = $groups[(string) ($choice['category_id'] ?? '')] ?? [];
                $options[] = [
                    'ingredient_id' => $ingredientId,
                    'name' => $choice !== [] ? $this->rowLabel($choice) : '#'.$ingredientId,
                    'group' => $group !== [] ? $this->rowLabel($group) : '',
                    'default' => $link['default_ingredient'],
                    'extra_fee' => $link['extra_fee'],
                    'custom_fee' => $link['custom_fee'],
                ];
            }
            $linked += count($options);
            $out[] = [
                'id' => $itemId,
                'name_ar' => (string) ($item['name_ar'] ?? ''),
                'name_en' => (string) ($item['name_en'] ?? ''),
                'name_he' => (string) ($item['name_he'] ?? ''),
                'options' => $options,
            ];
        }

        return [
            'ok' => true,
            'summary' => count($out).' meal'.(count($out) === 1 ? '' : 's').', '.$linked.' linked options',
            'content' => $this->jsonForModel(['meals' => $out], 16000),
        ];
    }

    /**
     * Link / unlink option values on meals and switch "default" and "extra price" on a link.
     * Kaman keeps these on the cashier API: /items/{id}/ingredients[/{ingredientId}].
     *
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, content: string}
     */
    private function editItemOptions(array $arguments, string $token, string $baseUrl): array
    {
        $operations = $arguments['operations'] ?? [];
        if (is_array($operations) && ! array_is_list($operations)) {
            $operations = [$operations];
        }
        if (! is_array($operations) || $operations === []) {
            return [
                'ok' => false,
                'summary' => 'No option edits given',
                'content' => json_encode(['error' => 'operations is required'], JSON_UNESCAPED_UNICODE),
            ];
        }

        $items = $this->fetchPaged($token, $baseUrl, '/items');
        $categories = $this->fetchPaged($token, $baseUrl, '/categories');
        $choices = $this->fetchCashierPaged($token, $baseUrl, '/ingredients');
        $groups = $this->fetchCashierPaged($token, $baseUrl, '/ingredient-categories');
        $links = [];
        $changed = [];
        $unchanged = [];
        $failed = [];
        $writes = 0;

        foreach (array_slice($operations, 0, 40) as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $action = strtolower(trim((string) ($operation['action'] ?? 'link')));
            if (! in_array($action, ['link', 'unlink', 'update'], true)) {
                $failed[] = 'Unknown action '.$action;
                continue;
            }

            $meals = $this->optionTargetMeals($operation, $items, $categories);
            if ($meals === []) {
                $failed[] = 'No meal matched '.trim(($operation['item'] ?? '').' '.($operation['menu_category'] ?? ''));
                continue;
            }
            $options = $this->optionTargetChoices($operation, $choices, $groups);
            if ($options === []) {
                $failed[] = 'No option matched '.trim(($operation['ingredient'] ?? '').' '.($operation['group'] ?? ''));
                continue;
            }

            foreach ($meals as $meal) {
                $itemId = $this->rowId($meal);
                $links[$itemId] ??= $this->itemOptionLinks($itemId, $token, $baseUrl);
                foreach ($options as $choice) {
                    $ingredientId = $this->rowId($choice);
                    $label = $this->rowLabel($meal).' · '.$this->rowLabel($choice);
                    $existing = $links[$itemId][$ingredientId] ?? null;

                    if ($writes >= self::MAX_OPTION_WRITES) {
                        $failed[] = 'Stopped after '.self::MAX_OPTION_WRITES.' changes. Ask again for the rest.';
                        break 3;
                    }

                    if ($action === 'unlink') {
                        if ($existing === null) {
                            $unchanged[] = $label.': was not linked';
                            continue;
                        }
                        $writes++;
                        $sent = $this->sendCashier('DELETE', '/items/'.$itemId.'/ingredients/'.$ingredientId, [], $token, $baseUrl);
                        if ($sent['ok']) {
                            unset($links[$itemId][$ingredientId]);
                            $changed[] = $label.': unlinked';
                        } else {
                            $failed[] = $label.': unlink HTTP '.$sent['status'].' '.mb_substr($sent['content'], 0, 160);
                        }
                        continue;
                    }

                    if ($action === 'update' && $existing === null) {
                        $failed[] = $label.': not linked to this meal (use link)';
                        continue;
                    }

                    $wanted = $this->wantedOptionLink($operation, $existing, $choice);
                    if ($existing !== null
                        && $existing['default_ingredient'] === $wanted['default_ingredient']
                        && $existing['extra_fee'] === $wanted['extra_fee']
                        && abs((float) ($existing['custom_fee'] ?? 0) - (float) ($wanted['custom_fee'] ?? 0)) < 0.009
                    ) {
                        $unchanged[] = $label.': already '.$this->describeOptionLink($wanted);
                        continue;
                    }

                    $writes++;
                    if ($existing === null) {
                        $body = [
                            'ingredient_id' => $ingredientId,
                            'default_ingredient' => $wanted['default_ingredient'],
                            'extra_fee' => $wanted['extra_fee'],
                        ];
                        if ($wanted['extra_fee']) {
                            $body['custom_fee'] = $wanted['custom_fee'];
                        }
                        $sent = $this->sendCashier('POST', '/items/'.$itemId.'/ingredients', $body, $token, $baseUrl);
                    } else {
                        $sent = $this->sendCashier('PUT', '/items/'.$itemId.'/ingredients/'.$ingredientId, $wanted, $token, $baseUrl);
                    }

                    if ($sent['ok']) {
                        $links[$itemId][$ingredientId] = $wanted + ['row' => []];
                        $changed[] = $label.': '.($existing === null ? 'linked, ' : '').$this->describeOptionLink($wanted);
                    } else {
                        $failed[] = $label.': HTTP '.$sent['status'].' '.mb_substr($sent['content'], 0, 160);
                    }
                }
            }
        }

        return [
            'ok' => $failed === [] && ($changed !== [] || $unchanged !== []),
            'summary' => count($changed).' option links changed'
                .($unchanged === [] ? '' : ', '.count($unchanged).' already right')
                .($failed === [] ? '' : ', '.count($failed).' failed'),
            'content' => $this->jsonForModel([
                'changed' => $changed,
                'already_right' => $unchanged,
                'failed' => $failed,
            ], 10000),
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    private function optionTargetMeals(array $operation, array $items, array $categories): array
    {
        $meals = [];
        $item = trim((string) ($operation['item'] ?? ''));
        if ($item !== '') {
            foreach ($this->matchByNameOrId($items, $item) as $row) {
                $meals[$this->rowId($row)] = $row;
            }
        }
        $section = trim((string) ($operation['menu_category'] ?? ''));
        if ($section !== '') {
            $sectionIds = array_map(fn (array $row): string => $this->rowId($row), $this->matchByNameOrId($categories, $section));
            foreach ($items as $row) {
                $categoryId = (string) ($row['category_id'] ?? ($row['category']['id'] ?? ''));
                if ($categoryId !== '' && in_array($categoryId, $sectionIds, true)) {
                    $meals[$this->rowId($row)] = $row;
                }
            }
        }
        unset($meals['']);

        return array_values($meals);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<array<string, mixed>>  $choices
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function optionTargetChoices(array $operation, array $choices, array $groups): array
    {
        $groupNeedle = trim((string) ($operation['group'] ?? ''));
        $groupIds = $groupNeedle === ''
            ? null
            : array_map(fn (array $row): string => $this->rowId($row), $this->matchByNameOrId($groups, $groupNeedle));
        if ($groupIds === []) {
            return [];
        }
        $inGroup = $groupIds === null
            ? $choices
            : array_values(array_filter($choices, static fn (array $row): bool => in_array((string) ($row['category_id'] ?? ''), $groupIds, true)));

        $ingredient = trim((string) ($operation['ingredient'] ?? ''));
        if ($ingredient === '') {
            return $groupIds === null ? [] : $inGroup;
        }

        return array_values(array_filter(
            $this->matchByNameOrId($inGroup, $ingredient),
            fn (array $row): bool => $this->rowId($row) !== '',
        ));
    }

    /**
     * Exact id, then exact name (any language), then names that contain the text.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function matchByNameOrId(array $rows, string $needle): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }
        $byId = array_values(array_filter($rows, fn (array $row): bool => $this->rowId($row) === $needle));
        if ($byId !== []) {
            return $byId;
        }

        $lower = mb_strtolower($needle);
        $exact = [];
        $partial = [];
        foreach ($rows as $row) {
            foreach ($this->rowNames($row) as $name) {
                $name = mb_strtolower(trim($name));
                if ($name === '') {
                    continue;
                }
                if ($name === $lower) {
                    $exact[] = $row;
                    continue 2;
                }
                if (str_contains($name, $lower)) {
                    $partial[] = $row;
                    continue 2;
                }
            }
        }

        return $exact !== [] ? $exact : $partial;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = $this->rowId($row);
            if ($id !== '') {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{default_ingredient: bool, extra_fee: bool, custom_fee: float|null, row: array<string, mixed>}>
     */
    private function itemOptionLinks(string $itemId, string $token, string $baseUrl): array
    {
        $sent = $this->sendCashier('GET', '/items/'.$itemId.'/ingredients', [], $token, $baseUrl);
        if (! $sent['ok']) {
            return [];
        }
        $out = [];
        foreach ($this->unwrapList(json_decode($sent['content'], true)) as $row) {
            $id = (string) ($row['ingredient_id'] ?? ($row['ingredient']['id'] ?? ($row['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $extra = filter_var($row['extra_fee'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $fee = $row['custom_fee'] ?? $row['extra_fee_cost'] ?? null;
            $out[$id] = [
                'default_ingredient' => filter_var($row['default_ingredient'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'extra_fee' => $extra,
                'custom_fee' => $extra && is_numeric($fee) ? round((float) $fee, 2) : null,
                'row' => $row,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $choice
     * @return array{default_ingredient: bool, extra_fee: bool, custom_fee: float|null}
     */
    private function wantedOptionLink(array $operation, ?array $existing, array $choice): array
    {
        $default = array_key_exists('default', $operation) && $operation['default'] !== null
            ? filter_var($operation['default'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($existing['default_ingredient'] ?? false);

        $fee = isset($operation['custom_fee']) && is_numeric($operation['custom_fee'])
            ? round((float) $operation['custom_fee'], 2)
            : null;
        $choicePrice = is_numeric($choice['price'] ?? null) ? round((float) $choice['price'], 2) : 0.0;

        if (array_key_exists('extra_fee', $operation) && $operation['extra_fee'] !== null) {
            $extra = filter_var($operation['extra_fee'], FILTER_VALIDATE_BOOLEAN);
        } elseif ($fee !== null) {
            $extra = $fee > 0;
        } elseif ($existing !== null) {
            $extra = (bool) $existing['extra_fee'];
        } else {
            $extra = $choicePrice > 0;
        }

        if (! $extra) {
            return ['default_ingredient' => $default, 'extra_fee' => false, 'custom_fee' => null];
        }

        $fee ??= isset($existing['custom_fee']) && (float) $existing['custom_fee'] > 0
            ? (float) $existing['custom_fee']
            : $choicePrice;

        return ['default_ingredient' => $default, 'extra_fee' => true, 'custom_fee' => round((float) $fee, 2)];
    }

    /**
     * @param  array{default_ingredient: bool, extra_fee: bool, custom_fee: float|null}  $link
     */
    private function describeOptionLink(array $link): string
    {
        return ($link['default_ingredient'] ? 'default on' : 'default off')
            .', '.($link['extra_fee'] ? 'extra price '.number_format((float) $link['custom_fee'], 2, '.', '') : 'no extra price');
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
                    'description' => 'Open a Wolt restaurant URL, a public Kaman menu URL, or a public restaurant website and copy the full menu onto this restaurant, including item photos. The public menu is checked afterwards.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                                'description' => 'https://wolt.com/.../restaurant/..., a public https://name.kaman.rest/menu link, or a restaurant website such as https://example.com/',
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
                    'name' => 'read_item_options',
                    'description' => 'Show which option values (ingredients / add-ons) are linked to a meal, with their group, whether each is selected by default, and whether it charges an extra price.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'item' => [
                                'type' => 'string',
                                'description' => 'Meal name (any language) or meal id. An exact name wins over partial matches.',
                            ],
                        ],
                        'required' => ['item'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'edit_item_options',
                    'description' => 'Link or unlink option values (ingredients / add-ons) on meals, and turn "selected by default" and "extra price" on or off for a linked option.',
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
                                        'action' => [
                                            'type' => 'string',
                                            'enum' => ['link', 'unlink', 'update'],
                                            'description' => 'link = add the option to the meal (or update it if already linked). unlink = remove it from the meal. update = change default / extra price on an option already linked.',
                                        ],
                                        'item' => [
                                            'type' => 'string',
                                            'description' => 'Meal name or meal id. Leave empty when using menu_category.',
                                        ],
                                        'menu_category' => [
                                            'type' => 'string',
                                            'description' => 'Apply to every meal in this menu section (name or id), e.g. Pizzas.',
                                        ],
                                        'ingredient' => [
                                            'type' => 'string',
                                            'description' => 'Option value name or id, e.g. Extra cheese.',
                                        ],
                                        'group' => [
                                            'type' => 'string',
                                            'description' => 'Option group name or id, e.g. Choose a sauce. Without ingredient it means every value in that group.',
                                        ],
                                        'default' => [
                                            'type' => 'boolean',
                                            'description' => 'true = selected by default. false = not selected by default. Omit to keep.',
                                        ],
                                        'extra_fee' => [
                                            'type' => 'boolean',
                                            'description' => 'true = charges an extra price. false = free. Omit to keep.',
                                        ],
                                        'custom_fee' => [
                                            'type' => 'number',
                                            'description' => 'Extra price amount, e.g. 3. Setting it above 0 turns extra_fee on.',
                                        ],
                                    ],
                                    'required' => ['action'],
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
            'read_item_options' => $this->readItemOptions($arguments, $token, $baseUrl),
            'edit_item_options' => $this->editItemOptions($arguments, $token, $baseUrl),
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

        // Without a status Kaman stores the meal as 0, and the public menu hides it.
        if ($method === 'POST' && $normalized === '/items' && ! isset($body['status'])) {
            $body['status'] = '1';
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
        if ($name === 'read_item_options') {
            return ['item' => (string) ($arguments['item'] ?? '')];
        }
        if ($name === 'edit_menu' || $name === 'edit_item_options') {
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
