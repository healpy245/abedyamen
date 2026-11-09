<?php

declare(strict_types=1);

namespace Tests\Feature\Form;

use App\Models\FormWorkflowRun;
use App\Models\User;
use App\Services\Form\FormMenuAgentMemory;
use App\Services\Form\KamanChatCredentials;
use App\Services\Form\WebsiteMenuSource;
use App\Services\Haat\KamanPublicMenuScraper;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FormMenuAgentWebsiteImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://labellamesso.jadtags.online';

    private const TARGET = 'https://labellamesso.kaman.rest';

    private const SESSION_BASE = 'https://alarishe.kaman.rest/api/manager';

    /** @var array{categories: list<array<string, mixed>>, items: list<array<string, mixed>>, public_blank: bool, translate: ?callable, posts_without_status: int} */
    private array $kaman = [
        'categories' => [],
        'items' => [],
        'public_blank' => false,
        'translate' => null,
        'posts_without_status' => 0,
    ];

    public function test_jadtags_parser_reads_each_dish_once_with_badge_prices_and_size_splits(): void
    {
        $parsed = app(WebsiteMenuSource::class)->parseJadtagsHtml($this->jadtagsMealsPage());

        $names = array_column($parsed['meals'], 'source_name');
        $this->assertSame([
            'فوكاتشا',
            'شوربة اليوم',
            'صدف بالليمون 250 غرام',
            'صدف بالليمون 500 غرام',
            'سلطة لابيلا ميسو صغير',
            'سلطة لابيلا ميسو كبير',
            'سوفليه',
            'كولا',
        ], $names);

        $prices = array_column($parsed['meals'], 'price', 'source_name');
        $this->assertSame('25.00', $prices['فوكاتشا']);
        $this->assertSame('53.00', $prices['صدف بالليمون 250 غرام']);
        $this->assertSame('106.00', $prices['صدف بالليمون 500 غرام']);
        $this->assertSame('29.00', $prices['سلطة لابيلا ميسو صغير']);
        $this->assertSame('39.00', $prices['سلطة لابيلا ميسو كبير']);
        $this->assertStringNotContainsString('53106', json_encode($parsed, JSON_UNESCAPED_UNICODE));

        $this->assertSame([[
            'name' => 'سلطة لابيلا ميسو',
            'description_prices' => ['38.00', '52.00'],
            'badge_prices' => ['29.00', '39.00'],
        ]], $parsed['price_conflicts']);
        $this->assertCount(2, $parsed['size_splits']);

        $meals = array_column($parsed['meals'], null, 'source_name');
        $this->assertSame('يشمل ليمون واعشاب', $meals['صدف بالليمون 500 غرام']['description_ar']);
        $this->assertSame($meals['صدف بالليمون 250 غرام']['image_url'], $meals['صدف بالليمون 500 غرام']['image_url']);
        $this->assertSame('', $meals['شوربة اليوم']['description_ar']);
    }

    public function test_jadtags_br_becomes_a_space_and_prose_options_stay_in_the_description(): void
    {
        $parsed = app(WebsiteMenuSource::class)->parseJadtagsHtml($this->jadtagsPage([[
            'title' => 'باستا',
            'items' => [[
                'id' => '26',
                'name' => 'باستا دجاج',
                'price' => '₪67',
                'description' => '<p><strong>اختر نوع المعكرونه:</strong> بيني | لنجويني 10₪.<br><strong>وزن:</strong> 500<br>غرام</p>',
            ]],
        ]]));

        $this->assertSame('اختر نوع المعكرونه: بيني | لنجويني 10₪. وزن: 500 غرام', $parsed['meals'][0]['description_ar']);
        $this->assertSame('67.00', $parsed['meals'][0]['price']);
        $this->assertSame([], $parsed['unique_ingredient_categories']);
        $this->assertSame([], $parsed['unique_ingredients']);
    }

    public function test_two_badge_prices_without_named_sizes_never_merge_and_are_reported(): void
    {
        $parsed = app(WebsiteMenuSource::class)->parseJadtagsHtml($this->jadtagsPage([[
            'title' => 'مقبلات',
            'items' => [['id' => '5', 'name' => 'حمص', 'price' => '₪20 | ₪30', 'description' => '<p>مع زيت</p>']],
        ]]));

        $this->assertCount(1, $parsed['meals']);
        $this->assertSame('20.00', $parsed['meals'][0]['price']);
        $this->assertSame('unnamed_prices', $parsed['warnings'][0]['type']);
    }

    public function test_a_category_with_no_dishes_is_not_created(): void
    {
        $parsed = app(WebsiteMenuSource::class)->parseJadtagsHtml($this->jadtagsPage([
            ['title' => 'مقبلات', 'items' => [['id' => '1', 'name' => 'فقع', 'price' => '39', 'description' => '']]],
            ['title' => 'عروض', 'items' => []],
        ]));

        $this->assertSame(['مقبلات'], array_column($parsed['categories'], 'name_ar'));
    }

    public function test_category_order_follows_the_menu_page_and_titles_stay_as_written(): void
    {
        $order = ['مقبلات', 'نودلز', 'تحلاي'];
        $parsed = app(WebsiteMenuSource::class)->parseJadtagsHtml($this->jadtagsPage([
            ['title' => 'تحلاي', 'items' => [['id' => '3', 'name' => 'سوفليه', 'price' => '35', 'description' => '']]],
            ['title' => 'مقبلات', 'items' => [['id' => '1', 'name' => 'فقع', 'price' => '39', 'description' => '']]],
            ['title' => 'نودلز', 'items' => [['id' => '2', 'name' => 'نودلز دجاج', 'price' => '59', 'description' => '']]],
        ]), $order);

        $this->assertSame($order, array_column($parsed['categories'], 'name_ar'));
        $this->assertSame([1, 2, 3], array_column($parsed['categories'], 'order_index'));
        $this->assertSame('Desserts', $parsed['categories'][2]['name_en']);
        $this->assertSame('קינוחים', $parsed['categories'][2]['name_he']);
    }

    public function test_generic_crawler_follows_menu_links_and_reads_repeated_cards(): void
    {
        $card = fn (string $name, string $price): string => '<div class="dish"><img src="/img/'.md5($name).'.jpg"><h3>'.$name.'</h3><p class="desc">Fresh</p><span class="price">'.$price.'</span></div>';
        $menu = '<html><body><h2>Starters</h2>'.$card('Soup', '₪20').$card('Bread', '₪10').'<h2>Mains</h2>'.$card('Steak', '₪90').$card('Fish', '₪80').'</body></html>';
        Http::fake(function ($request) use ($menu) {
            return match (rtrim($request->url(), '/')) {
                'https://restaurant.example.com' => Http::response('<html><body><a href="/our-menu">Menu</a><a href="/cart">Cart</a></body></html>'),
                'https://restaurant.example.com/our-menu' => Http::response($menu),
                default => Http::response('', 404),
            };
        });

        $parsed = app(WebsiteMenuSource::class)->parse('https://restaurant.example.com/');

        $this->assertSame(['Starters', 'Mains'], array_column($parsed['categories'], 'name_en'));
        $this->assertSame(['Soup', 'Bread', 'Steak', 'Fish'], array_column($parsed['meals'], 'name_en'));
        $this->assertSame('90.00', $parsed['meals'][2]['price']);
        $this->assertSame('https://restaurant.example.com/img/'.md5('Steak').'.jpg', $parsed['meals'][2]['image_url']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/cart'));
    }

    public function test_page_without_stable_cards_uses_the_model_only_as_a_fallback(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman(
            sourcePages: [
                '' => '<html><body><div>Our menu today: Soup is twenty shekels.</div></body></html>',
            ],
            sourceModel: json_encode(['categories' => [[
                'name' => 'مقبلات',
                'items' => [['name' => 'شوربة', 'description' => '', 'price' => '20', 'image_url' => 'https://evil.example.com/made-up.jpg']],
            ]]], JSON_UNESCAPED_UNICODE),
        );

        $body = $this->chat('import the menu from '.self::SOURCE.'/');

        $this->assertStringContainsString('verified on the public menu', $body);
        $this->assertSame('شوربة', $this->kaman['items'][0]['name_ar']);
        $this->assertSame('', $this->kaman['items'][0]['image']);
    }

    public function test_golden_request_builds_the_menu_on_the_restaurant_in_the_message(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $secret = 'pw-'.Str::random(20);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $body = $this->chat('analyze the following link '.self::SOURCE.'/ and extract menu, and build it with images in the following restaurant credentials: labellamesso.kaman.rest/login, owner@example.com, '.$secret);

        $this->assertSame(4, count($this->kaman['categories']));
        $this->assertSame(8, count($this->kaman['items']));
        $this->assertSame(0, $this->kaman['posts_without_status']);
        foreach ($this->kaman['items'] as $item) {
            $this->assertSame('1', $item['status']);
            $this->assertNotSame('', $item['image']);
            $this->assertMatchesRegularExpression('/\p{Hebrew}/u', $item['name_he']);
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $item['name_he']);
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $item['name_en']);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $item['name_ar']);
            $this->assertNotSame('', $item['description_he']);
        }
        $this->assertSame(
            ['مقبلات', 'سلطات', 'تحلاي', 'بارد'],
            array_column($this->kaman['categories'], 'name_ar'),
        );
        $this->assertSame('Desserts', $this->kaman['categories'][2]['name_en']);
        $prices = array_column($this->kaman['items'], 'price', 'name_ar');
        $this->assertSame('53.00', $prices['صدف بالليمون 250 غرام']);
        $this->assertSame('106.00', $prices['صدف بالليمون 500 غرام']);

        $this->assertStringContainsString('verified on the public menu: 4 categories, 8 dishes, 8 photos', $body);
        $this->assertStringContainsString('Appetizers: 4', $body);
        $this->assertStringContainsString('صدف بالليمون', $body);
        $this->assertStringContainsString('the description says 38.00 / 52.00, the price badge says 29.00 / 39.00', $body);

        $run = FormWorkflowRun::query()->where('method_type', 'Menu Agent')->firstOrFail();
        $this->assertSame(FormWorkflowRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('labellamesso', $run->subdomain);

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString($secret, json_encode($run->payload).json_encode($run->result));
        $this->assertStringNotContainsString($secret, json_encode(app(FormMenuAgentMemory::class)->get(
            (int) $this->formUser()->id,
            (string) $run->payload['conversation_id'],
        )));
        $this->assertStringNotContainsString($secret, implode("\n", $logged));

        Http::assertSent(fn ($request) => $request->url() === self::TARGET.'/api/manager/login');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alarishe'));
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/login')
            && str_contains($request->body(), $secret));
    }

    public function test_writer_success_with_an_empty_public_menu_is_a_failed_job(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['public_blank'] = true;

        $body = $this->chat('build the menu from '.self::SOURCE.'/ on this restaurant');

        $this->assertStringContainsString('NOT complete', $body);
        $this->assertStringContainsString('The public menu shows no products', $body);
        $this->assertStringNotContainsString('verified on the public menu', $body);
        $this->assertSame(FormWorkflowRun::STATUS_FAILED, FormWorkflowRun::query()->firstOrFail()->status);
    }

    public function test_hidden_empty_state_text_does_not_count_as_an_empty_menu(): void
    {
        $scraper = app(KamanPublicMenuScraper::class);
        $text = 'لا توجد منتجات متاحة حالياً.';

        $page = $scraper->inspect('<button class="z-chip menu-chip" data-target="all"></button><button class="z-chip menu-chip" data-target="section-c1"></button><p class="z-empty d-none" id="menu-no-results">'.$text.'</p>');
        $this->assertFalse($page['empty_visible']);
        $this->assertSame(['c1'], $page['chips']);
        $this->assertTrue($scraper->inspect('<p class="z-empty" id="menu-no-results">'.$text.'</p>')['empty_visible']);
    }

    public function test_arabic_left_in_hebrew_or_english_stops_the_job_before_any_write(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['translate'] = fn (array $row): array => [
            'id' => $row['id'],
            'name_ar' => $row['name'],
            'name_en' => $row['name'],
            'name_he' => $row['name'],
            'description_ar' => $row['description'],
            'description_en' => $row['description'],
            'description_he' => $row['description'],
        ];

        $body = $this->chat('build the menu from '.self::SOURCE.'/ on this restaurant');

        $this->assertStringContainsString('Translation failed', $body);
        $this->assertSame([], $this->kaman['items']);
        $this->assertSame(FormWorkflowRun::STATUS_FAILED, FormWorkflowRun::query()->firstOrFail()->status);
    }

    public function test_a_price_dropped_by_the_translation_is_rejected(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['translate'] = fn (array $row): array => [
            'id' => $row['id'],
            'name_ar' => $row['name'],
            'name_en' => 'Dish',
            'name_he' => 'מנה',
            'description_ar' => $row['description'],
            'description_en' => $row['description'] === '' ? '' : 'Description',
            'description_he' => $row['description'] === '' ? '' : 'תיאור',
        ];

        $body = $this->chat('build the menu from '.self::SOURCE.'/ on this restaurant');

        $this->assertStringContainsString('Translation failed', $body);
        $this->assertSame([], $this->kaman['items']);
    }

    public function test_existing_dish_is_left_alone_except_for_being_made_visible(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['categories'][] = ['id' => 'c-old', 'name_ar' => 'مقبلات القديمة', 'name_en' => 'Old starters', 'name_he' => 'ישן'];
        $this->kaman['items'][] = [
            'id' => 'i-old',
            'name_ar' => 'فوكاتشا',
            'name_en' => 'My focaccia',
            'name_he' => 'פוקאצ׳ה שלי',
            'description_ar' => 'وصف المطعم',
            'description_en' => 'Owner text',
            'description_he' => 'טקסט',
            'price' => '99.00',
            'category_id' => 'c-old',
            'status' => '0',
            'image' => 'https://labellamesso.kaman.rest/storage/old.jpg',
        ];

        $body = $this->chat('build the menu from '.self::SOURCE.'/ on this restaurant');

        $copies = array_values(array_filter($this->kaman['items'], fn (array $item): bool => $item['name_ar'] === 'فوكاتشا'));
        $this->assertCount(1, $copies);
        $this->assertSame('1', $copies[0]['status']);
        $this->assertSame('My focaccia', $copies[0]['name_en']);
        $this->assertSame('99.00', $copies[0]['price']);
        $this->assertSame('c-old', $copies[0]['category_id']);
        $this->assertStringContainsString('verified on the public menu', $body);
    }

    public function test_rerun_does_not_copy_a_size_dish_spelled_in_the_feminine(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['items'][] = [
            'id' => 'i-old',
            'name_ar' => 'سلطة لابيلا ميسو صغيرة',
            'name_en' => 'Salad small',
            'name_he' => 'סלט קטן',
            'description_ar' => '',
            'description_en' => '',
            'description_he' => '',
            'price' => '29.00',
            'category_id' => '',
            'status' => '1',
            'image' => self::TARGET.'/storage/old.jpg',
        ];

        $this->chat('build the menu from '.self::SOURCE.'/ on this restaurant');

        $names = array_column($this->kaman['items'], 'name_ar');
        $this->assertContains('سلطة لابيلا ميسو صغيرة', $names);
        $this->assertNotContains('سلطة لابيلا ميسو صغير', $names);
        $this->assertContains('سلطة لابيلا ميسو كبير', $names);
    }

    public function test_rebuild_updates_an_existing_dish_instead_of_copying_it(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $this->kaman['items'][] = [
            'id' => 'i-old',
            'name_ar' => 'فوكاتشا',
            'name_en' => 'My focaccia',
            'name_he' => 'פוקאצ׳ה שלי',
            'description_ar' => '',
            'description_en' => '',
            'description_he' => '',
            'price' => '99.00',
            'category_id' => '',
            'status' => '1',
            'image' => '',
        ];

        $this->chat('rebuild the menu from '.self::SOURCE.'/ on this restaurant');

        $copies = array_values(array_filter($this->kaman['items'], fn (array $item): bool => $item['name_ar'] === 'فوكاتشا'));
        $this->assertCount(1, $copies);
        $this->assertSame('25.00', $copies[0]['price']);
    }

    public function test_failed_message_login_never_falls_back_to_the_session_restaurant(): void
    {
        config(['openai.api_key' => 'test-key']);
        $secret = 'pw-'.Str::random(20);
        Http::fake(function ($request) {
            return str_ends_with($request->url(), '/login')
                ? Http::response(['message' => 'Invalid credentials'], 401)
                : Http::response(['data' => []]);
        });

        $response = $this->actingAs($this->formUser())
            ->withSession(['full_ai_kaman_token' => 'session-token', 'full_ai_kaman_base_url' => self::SESSION_BASE])
            ->post(route('form.menu-agent.chat'), ['messages' => [[
                'role' => 'user',
                'content' => 'build '.self::SOURCE.'/ on labellamesso.kaman.rest/login owner@example.com '.$secret,
            ]]]);

        $response->assertUnauthorized();
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringContainsString(KamanChatCredentials::HIDDEN, $response->json('redacted_message'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alarishe'));
        $this->assertSame(0, FormWorkflowRun::query()->count());
    }

    public function test_message_login_works_without_any_form_session_and_follow_ups_stay_on_that_restaurant(): void
    {
        config(['openai.api_key' => 'test-key']);
        $this->fakeKaman();
        $secret = 'pw-'.Str::random(20);
        $conversation = (string) Str::uuid();
        $message = 'build '.self::SOURCE.'/ on labellamesso.kaman.rest/login, owner@example.com, '.$secret;
        $user = $this->formUser();

        $first = $this->actingAs($user)->post(route('form.menu-agent.chat'), [
            'conversation_id' => $conversation,
            'messages' => [['role' => 'user', 'content' => $message]],
        ]);
        $first->assertOk();
        $this->assertStringContainsString('verified on the public menu', $first->streamedContent());

        $this->assertSame('labellamesso', FormWorkflowRun::query()->firstOrFail()->subdomain);
        $this->assertSame(
            self::TARGET.'/api/manager',
            app(FormMenuAgentMemory::class)->get((int) $user->id, $conversation)['kaman']['base_url'],
        );

        // The browser sends the old message again. It must not log in again or reach the model.
        $second = $this->actingAs($user)
            ->withSession(['full_ai_kaman_token' => 'session-token', 'full_ai_kaman_base_url' => self::SESSION_BASE])
            ->post(route('form.menu-agent.chat'), [
                'conversation_id' => $conversation,
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                    ['role' => 'assistant', 'content' => 'Done.'],
                    ['role' => 'user', 'content' => 'thanks, what is next?'],
                ],
            ]);
        $second->assertOk();
        $second->streamedContent();
        $this->assertSame('labellamesso', FormWorkflowRun::query()->firstOrFail()->refresh()->subdomain);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/login')));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            && str_contains($request->body(), 'what is next'));
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/login')
            && str_contains($request->body(), $secret));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alarishe'));
    }

    public function test_credentials_parser_finds_the_login_and_hides_the_password(): void
    {
        $parser = app(KamanChatCredentials::class);
        $secret = 'pw-'.Str::random(12);

        $found = $parser->parse('copy https://other.kaman.rest/menu to bar.kaman.dev/login, owner@example.com, '.$secret.',');
        $this->assertSame('https://bar.kaman.dev/api/manager', $found['base_url']);
        $this->assertSame($secret, $found['password']);
        $this->assertStringNotContainsString($secret, $found['text']);
        $this->assertStringContainsString('owner@example.com', $found['text']);

        $labelled = $parser->parse('foo.kaman.rest email: owner@example.com password: '.$secret);
        $this->assertSame($secret, $labelled['password']);

        $this->assertNull($parser->parse('add a meal to foo.kaman.rest at 50'));
        $this->assertNull($parser->parse('foo.kaman.rest/login, owner@example.com, '.KamanChatCredentials::HIDDEN));
    }

    public function test_only_public_https_addresses_can_be_crawled(): void
    {
        $source = app(WebsiteMenuSource::class);

        $this->assertTrue($source->isAllowedUrl(self::SOURCE.'/'));
        foreach ([
            'http://labellamesso.jadtags.online/',
            'https://localhost/admin',
            'https://127.0.0.1/',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.5/',
            'https://intranet/',
        ] as $url) {
            $this->assertFalse($source->isAllowedUrl($url), $url);
        }
    }

    public function test_website_link_without_an_import_request_goes_to_the_normal_agent(): void
    {
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            '*chat/completions*' => Http::response(['choices' => [['message' => ['content' => 'Nice website.']]]]),
        ]);

        $body = $this->chat('what do you think of '.self::SOURCE.'/ ?');

        $this->assertStringContainsString('Nice website.', $body);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'jadtags'));
    }

    public function test_menu_files_in_the_turn_win_over_a_website_named_next_to_them(): void
    {
        config(['openai.api_key' => 'test-key']);
        Http::fake([
            '*chat/completions*' => Http::response(['choices' => [['message' => ['content' => 'Reading your photo.']]]]),
            '*' => Http::response(['data' => []]),
        ]);

        $response = $this->actingAs($this->formUser())
            ->withSession(['full_ai_kaman_token' => 'session-token', 'full_ai_kaman_base_url' => self::SESSION_BASE])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [['role' => 'user', 'content' => 'build the menu from this photo, our site is '.self::SOURCE.'/']],
                'attachments' => [\Illuminate\Http\UploadedFile::fake()->image('menu.jpg')],
            ]);

        $response->assertOk();
        $response->streamedContent();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'jadtags'));
    }

    public function test_wolt_import_still_runs_and_creates_status_one(): void
    {
        config(['openai.api_key' => 'test-key']);
        $menu = ['queries' => [[
            'queryKey' => ['venue-assortment', 'category-listing'],
            'state' => ['data' => [
                'primary_language' => 'he',
                'categories' => [['id' => 'cat1', 'name' => 'מנות', 'item_ids' => ['item1']]],
                'items' => [['id' => 'item1', 'name' => 'שווארמה', 'description' => '', 'price' => 5500, 'images' => [], 'options' => []]],
                'options' => [],
            ]],
        ]]];
        Http::fake(function ($request) use ($menu) {
            $url = $request->url();
            if (str_contains($url, 'wolt.com')) {
                return Http::response('<html><script>'.json_encode($menu, JSON_UNESCAPED_UNICODE).'</script></html>');
            }
            if (str_contains($url, 'chat/completions')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['rows' => [
                    ['id' => 'categories:cat1', 'name_ar' => 'وجبات', 'name_en' => 'Meals', 'name_he' => 'מנות'],
                    ['id' => 'meals:item1', 'name_ar' => 'شاورما', 'name_en' => 'Shawarma', 'name_he' => 'שווארמה'],
                ]], JSON_UNESCAPED_UNICODE)]]]]);
            }
            if (str_contains($url, '/items/bulk')) {
                return Http::response([], 404);
            }

            return $request->method() === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => '77'], 201);
        });

        $body = $this->chat('build this https://wolt.com/en/isr/petah-tikva/restaurant/shawarma-balev');

        $this->assertStringContainsString('Copied the menu from the link', $body);
        $this->assertStringContainsString('Public menu check', $body);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/items')
            && $this->field($request, 'status') === '1');
    }

    public function test_public_kaman_menu_import_still_resolves_and_runs(): void
    {
        config(['openai.api_key' => 'test-key']);
        $html = '<button class="z-chip menu-chip" data-target="section-c1">وجبات</button>'
            .'<li class="menu-item-row" data-name="شاورما" data-cat="c1"><div class="z-item showItem" data-id="i1" data-name="شاورما" data-description="" data-price="55" data-type="sandwich" data-image=""></div></li>';
        Http::fake(function ($request) use ($html) {
            $url = $request->url();
            if ($url === 'https://source.kaman.rest/menu') {
                return Http::response($html);
            }
            if (str_contains($url, 'chat/completions')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['rows' => [
                    ['id' => 'categories:c1', 'name_ar' => 'وجبات', 'name_en' => 'Meals', 'name_he' => 'מנות'],
                    ['id' => 'meals:i1', 'name_ar' => 'شاورما', 'name_en' => 'Shawarma', 'name_he' => 'שווארמה'],
                ]], JSON_UNESCAPED_UNICODE)]]]]);
            }
            if (str_contains($url, '/items/bulk')) {
                return Http::response([], 404);
            }

            return $request->method() === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => '77'], 201);
        });

        $body = $this->chat('copy https://source.kaman.rest/menu here');

        $this->assertStringContainsString('Copied the menu from the link', $body);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/manager/items')
            && $request->method() === 'POST'
            && $this->field($request, 'status') === '1'
            && $this->field($request, 'name_en') === 'Shawarma');
    }

    public function test_full_store_from_photos_sends_status_one(): void
    {
        config(['openai.api_key' => 'test-key']);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                return Http::response(['choices' => [['message' => ['content' => json_encode(['categories' => [[
                    'name_ar' => 'وجبات', 'name_en' => 'Meals', 'name_he' => 'מנות',
                    'meals' => [['name_ar' => 'شاورما', 'name_en' => 'Shawarma', 'name_he' => 'שווארמה', 'price' => '55']],
                ]]], JSON_UNESCAPED_UNICODE)]]]]);
            }

            return $request->method() === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => '77'], 201);
        });

        $response = $this->actingAs($this->formUser())
            ->withSession(['full_ai_kaman_token' => 'session-token', 'full_ai_kaman_base_url' => self::SESSION_BASE])
            ->post(route('form.menu-agent.chat'), [
                'messages' => [['role' => 'user', 'content' => 'store this menu']],
                'attachments' => [\Illuminate\Http\UploadedFile::fake()->image('menu.jpg')],
            ]);
        $response->assertOk();
        $response->streamedContent();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/items')
            && $this->field($request, 'status') === '1');
    }

    private function chat(string $message): string
    {
        $response = $this->actingAs($this->formUser())
            ->withSession(['full_ai_kaman_token' => 'session-token', 'full_ai_kaman_base_url' => self::SESSION_BASE])
            ->post(route('form.menu-agent.chat'), ['messages' => [['role' => 'user', 'content' => $message]]]);
        $response->assertOk();

        return (string) preg_replace_callback(
            '/\\\\u([0-9a-f]{4})/i',
            static fn (array $match): string => (string) json_decode('"\\u'.$match[1].'"'),
            $response->streamedContent(),
        );
    }

    /**
     * A small Kaman that behaves like the real one: a POST without status is stored as 0, and the
     * public menu only lists status 1. The source site serves the fixture pages.
     *
     * @param  array<string, string>|null  $sourcePages
     */
    private function fakeKaman(?array $sourcePages = null, ?string $sourceModel = null): void
    {
        $pages = $sourcePages ?? [
            '' => '<html><body><a href="'.self::SOURCE.'/menu">القائمة</a></body></html>',
            '/menu' => $this->jadtagsMenuPage(),
            '/meals?id=1' => $this->jadtagsMealsPage(),
        ];
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');

        Http::fake(function ($request) use ($pages, $image, $sourceModel) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, 'chat/completions')) {
                $system = (string) ($request->data()['messages'][0]['content'] ?? '');
                if ($sourceModel !== null && str_contains($system, 'You read the text')) {
                    return Http::response(['choices' => [['message' => ['content' => $sourceModel]]]]);
                }

                return Http::response(['choices' => [['message' => ['content' => $this->translated($request)]]]]);
            }
            if (str_starts_with($url, self::SOURCE)) {
                if (str_contains($url, '/uploads/')) {
                    return Http::response($image, 200, ['Content-Type' => 'image/png']);
                }
                $path = rtrim(substr($url, strlen(self::SOURCE)), '/');

                return isset($pages[$path]) ? Http::response($pages[$path]) : Http::response('', 404);
            }
            if (str_ends_with($url, '/api/manager/login')) {
                return Http::response(['token' => 'message-token']);
            }
            if (str_contains($url, '/storage/')) {
                return Http::response($image, 200, ['Content-Type' => 'image/png']);
            }
            if (str_ends_with($url, '/menu')) {
                return Http::response($this->publicPage());
            }
            if (str_contains($url, '/api/cashier')) {
                return Http::response([], 404);
            }
            if (str_contains($url, '/items/bulk')) {
                return Http::response([], 404);
            }
            if (preg_match('#/api/manager/categories$#', $url) === 1) {
                if ($method === 'GET') {
                    return Http::response(['data' => $this->kaman['categories']]);
                }
                $id = 'c-'.(count($this->kaman['categories']) + 1);
                $this->kaman['categories'][] = [
                    'id' => $id,
                    'name_ar' => $this->field($request, 'name_ar'),
                    'name_en' => $this->field($request, 'name_en'),
                    'name_he' => $this->field($request, 'name_he'),
                    'order_index' => $this->field($request, 'order_index'),
                ];

                return Http::response(['id' => $id], 201);
            }
            if (preg_match('#/api/manager/items$#', $url) === 1) {
                if ($method === 'GET') {
                    return Http::response(['data' => array_map(fn (array $item): array => $item + ['image_url' => $item['image'] ?: null], $this->kaman['items'])]);
                }
                $id = 'i-'.(count($this->kaman['items']) + 1);
                if ($this->field($request, 'status') === null) {
                    $this->kaman['posts_without_status']++;
                }
                $this->kaman['items'][] = $this->itemFrom($request, $id);

                return Http::response(['id' => $id], 201);
            }
            if (preg_match('#/api/manager/items/([^/]+)$#', $url, $match) === 1 && $method !== 'GET') {
                foreach ($this->kaman['items'] as $index => $item) {
                    if ($item['id'] === $match[1]) {
                        $next = $this->itemFrom($request, $item['id']);
                        $next['image'] = $next['image'] !== '' ? $next['image'] : $item['image'];
                        $this->kaman['items'][$index] = $next;
                    }
                }

                return Http::response(['id' => $match[1]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFrom($request, string $id): array
    {
        $row = ['id' => $id];
        foreach (['name_ar', 'name_en', 'name_he', 'description_ar', 'description_en', 'description_he', 'price', 'category_id'] as $field) {
            $row[$field] = (string) $this->field($request, $field);
        }
        $row['status'] = $this->field($request, 'status') ?? '0';
        $hasImage = false;
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === 'image') {
                $hasImage = true;
            }
        }
        $row['image'] = $hasImage ? self::TARGET.'/storage/'.$id.'.jpg' : '';

        return $row;
    }

    private function publicPage(): string
    {
        if ($this->kaman['public_blank']) {
            return '<div id="MenuPage"><p class="z-empty" id="menu-no-results">لا توجد منتجات متاحة حالياً.</p></div>';
        }
        $live = array_filter($this->kaman['items'], fn (array $item): bool => $item['status'] === '1');
        $html = '<div id="MenuPage"><button type="button" class="z-chip active menu-chip" data-target="all">All</button>';
        foreach ($this->kaman['categories'] as $category) {
            $html .= '<button type="button" class="z-chip menu-chip" data-target="section-'.$category['id'].'">'.$category['name_ar'].'</button>';
        }
        foreach ($live as $item) {
            $html .= '<li class="menu-item-row" data-name="'.$item['name_ar'].'" data-cat="'.$item['category_id'].'">'
                .'<div class="z-item showItem cursor-pointer" data-id="'.$item['id'].'" data-name="'.$item['name_ar'].'" data-description="" data-price="'.(float) $item['price'].'" data-type="sandwich" data-image="'.$item['image'].'"></div></li>';
        }
        $hidden = $live === [] ? '' : ' d-none';

        return $html.'<p class="z-empty'.$hidden.'" id="menu-no-results">لا توجد منتجات متاحة حالياً.</p></div>';
    }

    private function translated($request): string
    {
        $rows = json_decode((string) ($request->data()['messages'][1]['content'] ?? ''), true)['rows'] ?? [];
        $out = [];
        foreach ($rows as $row) {
            if ($this->kaman['translate'] !== null) {
                $out[] = ($this->kaman['translate'])($row);

                continue;
            }
            $hash = substr(md5((string) $row['name']), 0, 6);
            $numbers = fn (string $text): string => implode(' ', preg_match_all('/[0-9]+/', $text, $found) ? $found[0] : []);
            $description = (string) ($row['description'] ?? '');
            $out[] = [
                'id' => $row['id'],
                'name_ar' => 'مترجم',
                'name_en' => trim('Dish '.$hash.' '.$numbers((string) $row['name'])),
                'name_he' => trim('מנה '.$hash.' '.$numbers((string) $row['name'])),
                'description_ar' => 'مترجم',
                'description_en' => $description === '' ? '' : trim('Text '.$numbers($description)),
                'description_he' => $description === '' ? '' : trim('תיאור '.$numbers($description)),
            ];
        }

        return json_encode(['rows' => $out], JSON_UNESCAPED_UNICODE);
    }

    private function field($request, string $key): ?string
    {
        $data = $request->data();
        if (isset($data[$key]) && ! is_array($data[$key])) {
            return (string) $data[$key];
        }
        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $key) {
                return is_scalar($part['contents'] ?? null) ? (string) $part['contents'] : null;
            }
        }

        return null;
    }

    private function jadtagsMenuPage(): string
    {
        $html = '<html><body><ul>';
        // Id 21 sits between the others on purpose: the order on the page wins, not the id.
        foreach ([['1', 'مقبلات'], ['21', 'سلطات'], ['3', 'تحلاي'], ['4', 'بارد']] as [$id, $title]) {
            $html .= '<li><a href="'.self::SOURCE.'/meals?id='.$id.'"><img src="'.self::SOURCE.'/uploads/categories/'.$id.'.jpg" alt=""><h2>'.$title.'</h2></a></li>';
        }

        return $html.'</ul></body></html>';
    }

    private function jadtagsMealsPage(): string
    {
        return $this->jadtagsPage([
            ['title' => 'مقبلات', 'items' => [
                ['id' => '1', 'name' => 'فوكاتشا', 'price' => '₪25', 'description' => '<p>خبز ايطالي</p>'],
                ['id' => '101', 'name' => 'شوربة اليوم', 'price' => '₪25', 'description' => ' '],
                ['id' => '10', 'name' => 'صدف بالليمون', 'price' => '₪53 | ₪106', 'description' => '<p><strong>اختر حجم: </strong>250 | 500<br>يشمل ليمون واعشاب</p>'],
            ]],
            ['title' => 'سلطات', 'items' => [
                ['id' => '6', 'name' => 'سلطة لابيلا ميسو', 'price' => '₪29 | ₪39', 'description' => '<p><strong>اختر حجم: </strong>صغير 38₪ | كبير 52₪<br><strong>مكونات:</strong> خضروات طازجه.</p>'],
            ]],
            ['title' => 'تحلاي', 'items' => [
                ['id' => '98', 'name' => 'سوفليه', 'price' => '₪35', 'description' => ' '],
            ]],
            ['title' => 'بارد', 'items' => [
                ['id' => '61', 'name' => 'كولا', 'price' => '10', 'description' => '<p>330 ملم</p>'],
            ]],
        ]);
    }

    /**
     * Same layout as a Jadtags /meals page: every category, then the same items again as
     * itemcategorysect, which must not be read a second time.
     *
     * @param  list<array{title: string, items: list<array{id: string, name: string, price: string, description: string}>}>  $categories
     */
    private function jadtagsPage(array $categories): string
    {
        $card = function (array $item, string $class): string {
            return '<div class="swiper-slide"><div class="itemmost '.$class.' openPopup" data-id="'.$item['id'].'">'
                .'<div class="addToCart" data-id="'.$item['id'].'"><img src="'.self::SOURCE.'/public/front/images/cart2.svg"></div>'
                .'<img class="ImgCategoryAll" src="'.self::SOURCE.'/uploads/meals/'.$item['id'].'.jpg" alt="">'
                .'<h2>'.$item['name'].'</h2>'
                .'<div style="display: none"><h4 class="fullDesc">'.$item['description'].'</h4></div>'
                .'<h3 class="price">'.$item['price'].'</h3>'
                .'</div></div>';
        };

        $html = '<html><body><div class="tab-pane" id="tabAll">';
        foreach ($categories as $category) {
            $html .= '<div class="categoryAll"><h1 class="TitleCtegory"><img class="imgCategoryAll" src="x.jpg" alt=""> '.$category['title'].'</h1><div class="swiper-wrapper">';
            foreach ($category['items'] as $item) {
                $html .= $card($item, 'itemcategoryAll');
            }
            $html .= '</div></div>';
        }
        $html .= '</div><div class="tab-pane" id="tabOne">';
        foreach ($categories as $category) {
            foreach ($category['items'] as $item) {
                $html .= $card($item, 'itemcategorysect');
            }
        }

        return $html.'</div></body></html>';
    }

    private function formUser(): User
    {
        if (! User::where('email', 'yamen@kaman.rest')->exists()) {
            $this->seed(WorkspaceUserSeeder::class);
        }

        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }
}
