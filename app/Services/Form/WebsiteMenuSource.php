<?php

declare(strict_types=1);

namespace App\Services\Form;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * Reads a menu from an ordinary restaurant website.
 *
 * Jadtags sites have a known layout and get their own parser. Anything else goes
 * through a capped same-host crawl that looks for repeated cards. When a site has
 * no stable cards the result carries `page_text` so the caller can ask the model.
 */
final class WebsiteMenuSource
{
    public const MAX_PAGES = 15;

    private const MENU_LINK = '/menu|meal|categor|food|dish|products?\b|قائمة|القائمة|منيو|وجبات|الاصناف|الأصناف|תפריט|מנות/iu';

    private const NOT_MENU_LINK = '/cart|checkout|login|logout|register|account|customer|contact|offers?\b|wall|\/site\/|\.(?:css|js|png|jpe?g|webp|gif|svg|ico|pdf|vcf|xml)(?:$|\?)/iu';

    private const SIZE_WORD = '/^(?:صغير|صغيرة|وسط|متوسط|متوسطة|كبير|كبيرة|small|medium|large|s|m|l|xl|קטן|קטנה|בינוני|בינונית|גדול|גדולה)$/iu';

    private const SIZE_LEAD = '/^\s*(?:(?:اختر|اختار|اختيار)\s+)?(?:ال)?حجم\s*:?\s*|^\s*(?:choose\s+(?:a\s+)?)?size\s*:?\s*|^\s*(?:בחר\s+)?גודל\s*:?\s*/iu';

    /** Standard category names, so they never depend on the model. */
    private const CATEGORY_NAMES = [
        'مقبلات' => ['Appetizers', 'מנות ראשונות'],
        'نودلز' => ['Noodles', 'נודלס'],
        'سلطات' => ['Salads', 'סלטים'],
        'باستا' => ['Pasta', 'פסטה'],
        'ريزوتو' => ['Risotto', 'ריזוטו'],
        'بيتسا' => ['Pizza', 'פיצה'],
        'رئيسية' => ['Main courses', 'מנות עיקריות'],
        'اضافات' => ['Sides', 'תוספות'],
        'اطفال' => ['Kids', 'ילדים'],
        'تحلاي' => ['Desserts', 'קינוחים'],
        'بارد' => ['Cold drinks', 'שתייה קרה'],
    ];

    /**
     * Only public https addresses. The link comes from a chat message, so the server
     * must not be pointed at its own network.
     */
    public function isAllowedUrl(string $url): bool
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || $host === 'localhost' || preg_match('/\.(?:localhost|local|internal|test)$/', $host) === 1) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }
        if (! str_contains($host, '.')) {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }
        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $url): array
    {
        if (! $this->isAllowedUrl($url)) {
            throw new \RuntimeException('That link is not a public https address.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $queue = [$url];
        $labels = [];
        $seen = [];
        $order = [];
        $collected = [];
        $texts = [];
        $opened = 0;

        while ($queue !== [] && count($seen) < self::MAX_PAGES) {
            $current = (string) array_shift($queue);
            $key = rtrim($current, '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $html = $this->fetch($current);
            if ($html === null) {
                continue;
            }
            $opened++;

            if ($this->looksLikeJadtags($html)) {
                return $this->parseJadtagsHtml($html, $order, $current);
            }

            $order = array_merge($order, $this->jadtagsCategoryOrder($html));
            $cards = $this->parseGenericHtml($html, $current, $labels[$key] ?? '');
            foreach ($cards as $category) {
                $collected[] = $category;
            }
            if ($cards === []) {
                $texts[] = $this->pageText($html, $current);
            }
            foreach ($this->menuLinks($html, $current, $host) as $link => $label) {
                $labels[rtrim($link, '/')] ??= $label;
                $queue[] = $link;
            }
        }

        if ($opened === 0) {
            throw new \RuntimeException('Could not open the website.');
        }

        $parsed = $this->build($this->mergeCategories($collected));
        if ($parsed['meals'] === []) {
            $parsed['page_text'] = mb_substr(implode("\n\n", $texts), 0, 18000);
        }

        return $parsed;
    }

    public function looksLikeJadtags(string $html): bool
    {
        return str_contains($html, 'categoryAll') && str_contains($html, 'TitleCtegory');
    }

    /**
     * /meals?id=1 holds every category. Items are listed in div.categoryAll and again later
     * as itemcategorysect, so only categoryAll is read.
     *
     * @param  list<string>  $order  category titles from the /menu page
     * @return array<string, mixed>
     */
    public function parseJadtagsHtml(string $html, array $order = [], string $pageUrl = ''): array
    {
        $xpath = $this->dom($this->unwrapHeadingBlocks($html));
        $categories = [];
        foreach ($xpath->query("//div[{$this->hasClass('categoryAll')}]") ?: [] as $block) {
            $title = $this->nodeText($xpath->query(".//h1[{$this->hasClass('TitleCtegory')}]", $block)?->item(0));
            if ($title === '') {
                continue;
            }
            $items = [];
            foreach ($xpath->query(".//div[{$this->hasClass('itemcategoryAll')}]", $block) ?: [] as $item) {
                if (! $item instanceof DOMElement) {
                    continue;
                }
                $items[] = [
                    'id' => trim($item->getAttribute('data-id')),
                    'name' => $this->nodeText($xpath->query('.//h2', $item)?->item(0)),
                    'badge' => $this->badgeText($xpath->query(".//h3[{$this->hasClass('price')}]", $item)?->item(0)),
                    'lines' => $this->nodeLines($xpath->query(".//*[{$this->hasClass('fullDesc')}]", $item)?->item(0)),
                    'image' => $this->imageOf($xpath, $item, $pageUrl, ".//img[{$this->hasClass('ImgCategoryAll')}]"),
                ];
            }
            $categories[] = ['title' => $title, 'items' => $items];
        }

        return $this->build($this->sortByTitles($categories, $order));
    }

    /**
     * Repeated cards on one page, grouped by the heading above them.
     *
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    public function parseGenericHtml(string $html, string $pageUrl = '', string $pageLabel = ''): array
    {
        $xpath = $this->dom($this->unwrapHeadingBlocks($html));
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer') ?: [] as $junk) {
            $junk->parentNode?->removeChild($junk);
        }

        $cards = $this->bestCards($xpath, $pageUrl);
        if ($cards === []) {
            return [];
        }

        $groups = [];
        $heading = $pageLabel;
        $body = $xpath->query('//body')?->item(0);
        if ($body !== null) {
            $this->walkCards($body, $cards, $heading, $groups);
        }

        return $groups;
    }

    /**
     * Rows the model pulled from a page that has no stable cards.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    public function fromExtractedRows(array $categories, string $pageText): array
    {
        $out = [];
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }
            $items = [];
            foreach ($category['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $description = trim((string) ($item['description'] ?? ''));
                $image = trim((string) ($item['image_url'] ?? ''));
                $items[] = [
                    'id' => '',
                    'name' => $this->clean((string) ($item['name'] ?? '')),
                    'badge' => (string) ($item['price'] ?? ''),
                    'lines' => $description === '' ? [] : array_values(array_filter(array_map(
                        fn (string $line): string => $this->clean($line),
                        preg_split('/\R/u', $description) ?: [],
                    ))),
                    // A URL the page never showed is an invention, not a photo.
                    'image' => $image !== '' && str_contains($pageText, $image) && $this->isAllowedUrl($image) ? $image : null,
                ];
            }
            $out[] = ['title' => $this->clean((string) ($category['name'] ?? '')), 'items' => $items];
        }

        return $this->build($out);
    }

    /**
     * @param  list<array{title: string, items: list<array<string, mixed>>}>  $categories
     * @return array<string, mixed>
     */
    private function build(array $categories): array
    {
        $rows = [];
        $meals = [];
        $splits = [];
        $conflicts = [];
        $warnings = [];
        $seen = [];

        foreach ($categories as $category) {
            $title = $this->clean((string) ($category['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $categoryId = 'site-cat-'.(count($rows) + 1);
            $mealCount = 0;

            foreach ($category['items'] ?? [] as $item) {
                $name = $this->clean((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $badge = (string) ($item['badge'] ?? '');
                $prices = $this->pricesIn($badge);
                $sourceId = trim((string) ($item['id'] ?? ''));
                $dedupeKey = $sourceId !== '' ? 'id:'.$sourceId : 'name:'.mb_strtolower($name).'|'.$badge;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $itemId = 'site-item-'.($sourceId !== '' ? $sourceId : count($seen));

                if ($prices === []) {
                    $warnings[] = ['type' => 'no_price', 'name' => $name];

                    continue;
                }

                $lines = is_array($item['lines'] ?? null) ? array_values($item['lines']) : [];
                $image = is_string($item['image'] ?? null) && $item['image'] !== '' ? $item['image'] : null;
                if ($image === null) {
                    $warnings[] = ['type' => 'no_photo', 'name' => $name];
                }

                $sizes = count($prices) > 1 ? $this->sizesFor($lines, count($prices), $this->scriptOf($name)) : null;
                if ($sizes === null) {
                    if (count($prices) > 1) {
                        $warnings[] = ['type' => 'unnamed_prices', 'name' => $name, 'prices' => array_map([$this, 'money'], $prices)];
                    }
                    $meals[] = $this->mealRow($itemId, $categoryId, $name, $this->money($prices[0]), $lines, $image);
                    $mealCount++;

                    continue;
                }

                $described = [];
                $split = ['name' => $name, 'sizes' => []];
                foreach ($sizes['tokens'] as $index => $token) {
                    $price = $this->money($prices[$index]);
                    if ($token['price'] !== null) {
                        $described[] = $this->money($token['price']);
                    }
                    $meals[] = $this->mealRow(
                        $itemId.'-'.($index + 1),
                        $categoryId,
                        $name.' '.$token['label'],
                        $price,
                        $sizes['rest'],
                        $image,
                    );
                    $mealCount++;
                    $split['sizes'][] = ['label' => $token['label'], 'price' => $price];
                }
                $splits[] = $split;
                if ($described !== [] && $described !== array_map([$this, 'money'], $prices)) {
                    $conflicts[] = [
                        'name' => $name,
                        'description_prices' => $described,
                        'badge_prices' => array_map([$this, 'money'], $prices),
                    ];
                }
            }

            if ($mealCount > 0) {
                $rows[] = $this->categoryRow($categoryId, $title, count($rows) + 1);
            }
        }

        return [
            'categories' => $rows,
            'meals' => $meals,
            'unique_ingredient_categories' => [],
            'unique_ingredients' => [],
            'size_splits' => $splits,
            'price_conflicts' => $conflicts,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function mealRow(string $id, string $categoryId, string $name, string $price, array $lines, ?string $image): array
    {
        $description = $this->clean(implode(' ', $lines));
        $nameField = 'name_'.$this->scriptOf($name);
        $descriptionField = 'description_'.($description !== '' ? $this->scriptOf($description) : $this->scriptOf($name));
        $row = [
            'haat_id' => $id,
            'source_name' => $name,
            'source_fields' => [$nameField, $descriptionField],
            'name_ar' => '',
            'name_en' => '',
            'name_he' => '',
            'description_ar' => '',
            'description_en' => '',
            'description_he' => '',
            'price' => $price,
            'image_url' => $image,
            'is_available' => true,
            'category_haat_id' => $categoryId,
            'menu_item_type' => 'Item',
            'groups' => [],
        ];
        $row[$nameField] = $name;
        $row[$descriptionField] = $description;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRow(string $id, string $title, int $order): array
    {
        $field = 'name_'.$this->scriptOf($title);
        $row = [
            'haat_id' => $id,
            'source_name' => $title,
            'source_fields' => [$field],
            'name_ar' => '',
            'name_en' => '',
            'name_he' => '',
            'description_ar' => '',
            'description_en' => '',
            'description_he' => '',
            'order_index' => $order,
        ];
        $row[$field] = $title;
        if (isset(self::CATEGORY_NAMES[$title])) {
            [$row['name_en'], $row['name_he']] = self::CATEGORY_NAMES[$title];
        }

        return $row;
    }

    /**
     * Matches a multi-price badge to the sizes the description names, in order.
     *
     * @param  list<string>  $lines
     * @return array{tokens: list<array{label: string, price: ?float}>, rest: list<string>}|null
     */
    private function sizesFor(array $lines, int $count, string $script): ?array
    {
        foreach ($lines as $index => $line) {
            $stripped = preg_replace(self::SIZE_LEAD, '', $line, 1, $led) ?? $line;
            $parts = array_values(array_filter(array_map('trim', explode('|', $stripped)), fn (string $part): bool => $part !== ''));
            if (count($parts) !== $count) {
                continue;
            }
            $tokens = [];
            foreach ($parts as $part) {
                $token = $this->sizeToken($part, $led > 0, $script);
                if ($token === null) {
                    $tokens = [];
                    break;
                }
                $tokens[] = $token;
            }
            if ($tokens === []) {
                continue;
            }
            $rest = $lines;
            unset($rest[$index]);

            return ['tokens' => $tokens, 'rest' => array_values($rest)];
        }

        return null;
    }

    /**
     * @return array{label: string, price: ?float}|null
     */
    private function sizeToken(string $part, bool $led, string $script): ?array
    {
        $price = null;
        $label = $part;
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:₪|ش\.?ج|شيكل|شيقل|nis|ils|ש"ח|ש״ח)|(?:₪)\s*(\d+(?:[.,]\d{1,2})?)/iu', $part, $match) === 1) {
            $price = (float) str_replace(',', '.', $match[1] !== '' ? $match[1] : $match[2]);
            $label = str_replace($match[0], ' ', $part);
        }
        $label = $this->clean($label);
        if ($label === '') {
            return null;
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $label) === 1) {
            return ['label' => $label.' '.['ar' => 'غرام', 'he' => 'גרם', 'en' => 'g'][$script], 'price' => $price];
        }
        $isUnitSize = preg_match('/^\d+(?:\.\d+)?\s*(?:غرام|غم|جرام|ملم|مل|لتر|g|gr|gram|grams|ml|l|גרם|מ"ל|מ״ל|ליטר)$/iu', $label) === 1;
        if (! $led && ! $isUnitSize && preg_match(self::SIZE_WORD, $label) !== 1) {
            return null;
        }

        return ['label' => $label, 'price' => $price];
    }

    /**
     * Every number in a price badge, kept apart. "₪53 | ₪106" is two prices, never 53106.
     *
     * @return list<float>
     */
    private function pricesIn(string $badge): array
    {
        $badge = strtr($badge, array_combine(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ));
        preg_match_all('/\d{1,3}(?:,\d{3})+(?!\d)|\d+(?:[.,]\d{1,2})?(?!\d)/', $badge, $matches);
        $prices = [];
        foreach ($matches[0] as $number) {
            $value = str_contains($number, ',') && preg_match('/,\d{3}$/', $number) === 1
                ? (float) str_replace(',', '', $number)
                : (float) str_replace(',', '.', $number);
            if ($value > 0) {
                $prices[] = $value;
            }
        }

        return $prices;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * libxml closes a heading when it meets a <p>, which throws <h4 class="fullDesc"><p>…</p></h4>
     * out of its element. Parsing it as a div keeps the text where the page put it.
     */
    private function unwrapHeadingBlocks(string $html): string
    {
        return preg_replace_callback(
            '#<h([1-6])\b([^>]*)>(\s*<(?:p|div|ul)\b.*?)</h\1>#su',
            static fn (array $match): string => '<div'.$match[2].'>'.$match[3].'</div>',
            $html,
        ) ?? $html;
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /**
     * Text with <br> and block ends kept as line breaks, so "500" never sticks to the next word.
     *
     * @return list<string>
     */
    private function nodeLines(?DOMNode $node): array
    {
        if ($node === null) {
            return [];
        }
        $copy = $node->cloneNode(true);
        $document = $copy->ownerDocument;
        if ($document === null) {
            return [];
        }
        $xpath = new DOMXPath($document);
        $root = $document->createElement('div');
        $root->appendChild($copy);
        foreach (iterator_to_array($xpath->query('.//br|.//p|.//div|.//li', $root) ?: []) as $element) {
            $break = $document->createTextNode("\n");
            if ($element->nodeName === 'br') {
                $element->parentNode?->replaceChild($break, $element);
            } else {
                $element->appendChild($break);
            }
        }
        $out = [];
        foreach (preg_split('/\n/u', $root->textContent) ?: [] as $line) {
            $line = $this->clean($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function nodeText(?DOMNode $node): string
    {
        return $node === null ? '' : $this->clean(implode(' ', $this->nodeLines($node)));
    }

    /**
     * Struck-out old prices are not selling prices.
     */
    private function badgeText(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $copy = $node->cloneNode(true);
        foreach (['del', 's', 'strike'] as $tag) {
            if ($copy instanceof DOMElement) {
                foreach (iterator_to_array($copy->getElementsByTagName($tag)) as $old) {
                    $old->parentNode?->removeChild($old);
                }
            }
        }

        return $this->clean((string) $copy->textContent);
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/[\x{200e}\x{200f}\x{202a}-\x{202e}\x{a0}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function scriptOf(string $text): string
    {
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return 'he';
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }

        return 'en';
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function fetch(string $url): ?string
    {
        $http = Http::timeout(60)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Accept' => 'text/html',
                'Accept-Language' => 'ar,he,en',
            ])
            ->withOptions(['allow_redirects' => [
                'max' => 4,
                'protocols' => ['https'],
                'on_redirect' => function ($request, $response, $uri): void {
                    if (! $this->isAllowedUrl((string) $uri)) {
                        throw new \RuntimeException('The website redirected to an address that is not allowed.');
                    }
                },
            ]]);
        if (! config('services.kaman.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }

        try {
            $response = $http->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Same-host links that lead to the menu, with the text they show.
     *
     * @return array<string, string>
     */
    private function menuLinks(string $html, string $pageUrl, string $host): array
    {
        $xpath = $this->dom($html);
        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $url = $this->absolute($anchor->getAttribute('href'), $pageUrl);
            if ($url === null || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $url = (string) preg_replace('/#.*$/', '', $url);
            $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
            $text = $this->clean((string) $anchor->textContent);
            if (preg_match(self::NOT_MENU_LINK, $path) === 1) {
                continue;
            }
            if (preg_match(self::MENU_LINK, $path) === 1 || preg_match(self::MENU_LINK, $text) === 1) {
                $links[$url] ??= mb_substr($text, 0, 60);
            }
        }

        return $links;
    }

    /**
     * Category titles in the order the /menu page lists them.
     *
     * @return list<string>
     */
    private function jadtagsCategoryOrder(string $html): array
    {
        if (! str_contains($html, 'meals?id=')) {
            return [];
        }
        $xpath = $this->dom($html);
        $titles = [];
        foreach ($xpath->query("//a[contains(@href, 'meals?id=')]") ?: [] as $anchor) {
            $title = $this->nodeText($xpath->query('.//h2', $anchor)?->item(0));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param  list<array{title: string, items: list<array<string, mixed>>}>  $categories
     * @param  list<string>  $order
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    private function sortByTitles(array $categories, array $order): array
    {
        if ($order === []) {
            return $categories;
        }
        $rank = array_flip(array_reverse($order));
        $count = count($order);
        $positions = [];
        foreach ($categories as $index => $category) {
            $positions[$index] = isset($rank[$category['title']])
                ? $count - 1 - $rank[$category['title']]
                : $count + $index;
        }
        uksort($categories, fn (int $a, int $b): int => $positions[$a] <=> $positions[$b]);

        return array_values($categories);
    }

    /**
     * The tag and class signature that repeats the most complete menu cards.
     *
     * @return array<string, array<string, mixed>> card fields keyed by node path
     */
    private function bestCards(DOMXPath $xpath, string $pageUrl): array
    {
        $groups = [];
        foreach ($xpath->query('//body//*[@class] | //body//li | //body//tr | //body//article') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            sort($classes);
            $groups[$element->nodeName.'.'.implode('.', $classes)][] = $element;
        }

        $best = [];
        $bestScore = 0.0;
        foreach ($groups as $elements) {
            if (count($elements) < 3) {
                continue;
            }
            $cards = [];
            $size = 0;
            foreach ($elements as $element) {
                $card = $this->cardFrom($xpath, $element, $pageUrl);
                if ($card !== null) {
                    // libxml re-wraps nodes on each access, so object ids are not stable.
                    $cards[$element->getNodePath()] = $card;
                    $size += strlen($element->textContent);
                }
            }
            if (count($cards) < 3 || count($cards) / count($elements) < 0.6) {
                continue;
            }
            // More cards win; among equals the tightest element is the real card, not a wrapper.
            $score = count($cards) - ($size / count($cards)) / 1000000;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $cards;
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cardFrom(DOMXPath $xpath, DOMElement $element, string $pageUrl): ?array
    {
        $priceNodes = $xpath->query(".//*[contains(translate(@class, 'PRICE', 'price'), 'price')][not(.//*[contains(translate(@class, 'PRICE', 'price'), 'price')])]", $element);
        if ($priceNodes === false) {
            return null;
        }
        $badge = '';
        if ($priceNodes->length === 1) {
            $badge = $this->badgeText($priceNodes->item(0));
        } elseif ($priceNodes->length === 0) {
            $matched = preg_match_all('/(?:₪\s*\d+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?\s*(?:₪|ش\.?ج|شيكل|nis|ils|ש"ח|ש״ח|\$|€))/iu', (string) $element->textContent, $found);
            if ($matched !== 1) {
                return null;
            }
            $badge = $found[0][0];
        } else {
            return null;
        }
        if ($this->pricesIn($badge) === []) {
            return null;
        }

        $name = '';
        $nameNodes = $xpath->query(".//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//*[contains(translate(@class, 'NAMETIL', 'nametil'), 'name') or contains(translate(@class, 'NAMETIL', 'nametil'), 'title')]", $element);
        foreach ($nameNodes ?: [] as $node) {
            $text = $this->nodeText($node);
            $class = $node instanceof DOMElement ? strtolower($node->getAttribute('class')) : '';
            if ($text !== '' && ! str_contains($class, 'price') && $this->clean($badge) !== $text) {
                $name = $text;
                break;
            }
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return null;
        }

        $lines = [];
        $descriptionNode = $xpath->query(".//*[contains(translate(@class, 'DESC', 'desc'), 'desc')]", $element)?->item(0)
            ?? $xpath->query('.//p', $element)?->item(0);
        if ($descriptionNode !== null) {
            $lines = $this->nodeLines($descriptionNode);
            if ($lines === [$name] || $lines === [$this->clean($badge)]) {
                $lines = [];
            }
        }

        $id = '';
        foreach (['data-id', 'data-item-id', 'data-product-id', 'data-meal-id'] as $attribute) {
            $id = trim($element->getAttribute($attribute));
            if ($id !== '') {
                break;
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'badge' => $badge,
            'lines' => $lines,
            'image' => $this->imageOf($xpath, $element, $pageUrl, './/img'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $cards
     * @param  list<array{title: string, items: list<array<string, mixed>>}>  $groups
     */
    private function walkCards(DOMNode $node, array $cards, string &$heading, array &$groups): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $id = $child->getNodePath();
            if (isset($cards[$id])) {
                $title = $heading !== '' ? $heading : 'Menu';
                $last = array_key_last($groups);
                if ($last === null || $groups[$last]['title'] !== $title) {
                    $groups[] = ['title' => $title, 'items' => []];
                    $last = array_key_last($groups);
                }
                $groups[$last]['items'][] = $cards[$id];

                continue;
            }
            if (in_array($child->nodeName, ['h1', 'h2', 'h3'], true)) {
                $text = $this->nodeText($child);
                if ($text !== '') {
                    $heading = $text;
                }

                continue;
            }
            $this->walkCards($child, $cards, $heading, $groups);
        }
    }

    /**
     * @param  list<array{title: string, items: list<array<string, mixed>>}>  $categories
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    private function mergeCategories(array $categories): array
    {
        $merged = [];
        foreach ($categories as $category) {
            $title = $category['title'];
            if (! isset($merged[$title])) {
                $merged[$title] = ['title' => $title, 'items' => []];
            }
            $merged[$title]['items'] = array_merge($merged[$title]['items'], $category['items']);
        }

        return array_values($merged);
    }

    private function imageOf(DOMXPath $xpath, DOMElement $element, string $pageUrl, string $query): ?string
    {
        $image = $xpath->query($query, $element)?->item(0);
        if (! $image instanceof DOMElement) {
            return null;
        }
        foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attribute) {
            $value = trim($image->getAttribute($attribute));
            if ($value === '' || str_starts_with($value, 'data:')) {
                continue;
            }
            $url = $this->absolute($value, $pageUrl);
            if ($url !== null && $this->isAllowedUrl($url)) {
                return str_replace(' ', '%20', $url);
            }
        }

        return null;
    }

    private function absolute(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:javascript|mailto|tel|fb|instagram|whatsapp|data):/i', $href) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $scheme = (string) parse_url($pageUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($pageUrl, PHP_URL_HOST);
        if ($scheme === '' || $host === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }
        $path = (string) parse_url($pageUrl, PHP_URL_PATH);
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $scheme.'://'.$host.$directory.$href;
    }

    /**
     * Plain text of a page with image addresses inline, for the model fallback only.
     */
    private function pageText(string $html, string $pageUrl): string
    {
        $xpath = $this->dom($this->unwrapHeadingBlocks($html));
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//head') ?: [] as $junk) {
            $junk->parentNode?->removeChild($junk);
        }
        foreach (iterator_to_array($xpath->query('//img') ?: []) as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }
            $src = $image->getAttribute('data-src') ?: $image->getAttribute('src');
            $url = $this->absolute($src, $pageUrl);
            if ($url !== null && $image->ownerDocument !== null) {
                $image->parentNode?->replaceChild($image->ownerDocument->createTextNode("\n[image: {$url}]\n"), $image);
            }
        }
        $body = $xpath->query('//body')?->item(0);

        return $body === null ? '' : implode("\n", $this->nodeLines($body));
    }
}
