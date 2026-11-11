<?php

declare(strict_types=1);

namespace Tests\Feature\Form;

use App\Models\User;
use App\Support\MenuNaming;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meal options (link / unlink / default / extra price), the shared naming guide,
 * and the extra file types the AI Menu agent reads.
 */
final class FormMenuAgentOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGER = 'https://pizzeria.kaman.rest/api/manager';

    private const CASHIER = 'https://pizzeria.kaman.rest/api/cashier';

    /** @var array<string, list<array<string, mixed>>> item id => linked options */
    private array $links = [];

    /** @var array<string, int> */
    private array $deleteStatus = [];

    public function test_link_option_with_default_on_and_extra_price(): void
    {
        $this->links = ['5' => []];
        $this->fakeAgent([
            ['action' => 'link', 'item' => 'Margherita', 'ingredient' => 'Extra cheese', 'default' => true, 'custom_fee' => 3],
        ]);

        $body = $this->ask('add extra cheese to the margherita for 3 shekels, selected by default');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::CASHIER.'/items/5/ingredients'
            && $r['ingredient_id'] === '30'
            && $r['default_ingredient'] === true
            && $r['extra_fee'] === true
            && (float) $r['custom_fee'] === 3.0);
        $this->assertStringContainsString('1 option links changed', $body);
    }

    public function test_link_without_price_uses_the_option_base_price(): void
    {
        $this->links = ['5' => []];
        $this->fakeAgent([
            ['action' => 'link', 'item' => 'Margherita', 'ingredient' => 'Olives'],
        ]);

        $this->ask('add olives to the margherita');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::CASHIER.'/items/5/ingredients'
            && $r['ingredient_id'] === '31'
            && $r['default_ingredient'] === false
            && $r['extra_fee'] === true
            && (float) $r['custom_fee'] === 2.5);
    }

    public function test_link_free_option_has_no_extra_price(): void
    {
        $this->links = ['5' => []];
        $this->fakeAgent([
            ['action' => 'link', 'item' => 'Margherita', 'ingredient' => 'Oregano'],
        ]);

        $this->ask('add oregano to the margherita');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::CASHIER.'/items/5/ingredients'
            && $r['ingredient_id'] === '32'
            && $r['extra_fee'] === false
            && ! array_key_exists('custom_fee', $r->data()));
    }

    public function test_unlink_removes_only_that_option_from_the_meal(): void
    {
        $this->links = ['5' => [$this->link('30', false, true, 3), $this->link('31', false, false, null)]];
        $this->fakeAgent([
            ['action' => 'unlink', 'item' => 'Margherita', 'ingredient' => 'Extra cheese'],
        ]);

        $body = $this->ask('remove extra cheese from the margherita');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === self::CASHIER.'/items/5/ingredients/30');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/ingredients/31'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/cashier/ingredients/30'));
        $this->assertStringContainsString('unlinked', $body);
    }

    public function test_unlink_of_an_option_that_is_not_linked_sends_nothing(): void
    {
        $this->links = ['5' => []];
        $this->fakeAgent([
            ['action' => 'unlink', 'item' => 'Margherita', 'ingredient' => 'Extra cheese'],
        ]);

        $body = $this->ask('remove extra cheese from the margherita');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertStringContainsString('was not linked', $body);
    }

    public function test_unlink_failure_is_reported_not_hidden(): void
    {
        $this->links = ['5' => [$this->link('30', false, true, 3)]];
        $this->deleteStatus = ['/items/5/ingredients/30' => 405];
        $this->fakeAgent([
            ['action' => 'unlink', 'item' => 'Margherita', 'ingredient' => 'Extra cheese'],
        ]);

        $body = $this->ask('remove extra cheese from the margherita');

        $this->assertStringContainsString('unlink HTTP 405', $body);
        $this->assertStringContainsString('1 failed', $body);
    }

    public function test_turn_default_off_and_extra_price_off(): void
    {
        $this->links = ['5' => [$this->link('30', true, true, 3)]];
        $this->fakeAgent([
            ['action' => 'update', 'item' => 'Margherita', 'ingredient' => 'Extra cheese', 'default' => false, 'extra_fee' => false],
        ]);

        $this->ask('cheese should not be default and should be free');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === self::CASHIER.'/items/5/ingredients/30'
            && $r['default_ingredient'] === false
            && $r['extra_fee'] === false
            && $r['custom_fee'] === null);
    }

    public function test_turn_default_on_keeps_the_existing_price(): void
    {
        $this->links = ['5' => [$this->link('30', false, true, 3)]];
        $this->fakeAgent([
            ['action' => 'update', 'item' => 'Margherita', 'ingredient' => 'Extra cheese', 'default' => true],
        ]);

        $this->ask('make cheese default on the margherita');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r['default_ingredient'] === true
            && $r['extra_fee'] === true
            && (float) $r['custom_fee'] === 3.0);
    }

    public function test_turn_extra_price_on_with_a_new_amount(): void
    {
        $this->links = ['5' => [$this->link('32', false, false, null)]];
        $this->fakeAgent([
            ['action' => 'update', 'item' => 'Margherita', 'ingredient' => 'Oregano', 'custom_fee' => 1.5],
        ]);

        $this->ask('oregano costs 1.5 now');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/items/5/ingredients/32')
            && $r['extra_fee'] === true
            && (float) $r['custom_fee'] === 1.5
            && $r['default_ingredient'] === false);
    }

    public function test_update_that_changes_nothing_sends_nothing(): void
    {
        $this->links = ['5' => [$this->link('30', true, true, 3)]];
        $this->fakeAgent([
            ['action' => 'update', 'item' => 'Margherita', 'ingredient' => 'Extra cheese', 'default' => true, 'custom_fee' => 3],
        ]);

        $body = $this->ask('make cheese default and 3 shekels');

        Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['PUT', 'POST', 'DELETE'], true) && str_contains($r->url(), '/ingredients'));
        $this->assertStringContainsString('already right', $body);
    }

    public function test_update_on_an_unlinked_option_fails_instead_of_linking(): void
    {
        $this->links = ['5' => []];
        $this->fakeAgent([
            ['action' => 'update', 'item' => 'Margherita', 'ingredient' => 'Extra cheese', 'default' => true],
        ]);

        $body = $this->ask('make cheese default');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/items/5/ingredients'));
        $this->assertStringContainsString('not linked to this meal', $body);
    }

    public function test_link_whole_group_to_every_meal_in_a_section(): void
    {
        $this->links = ['5' => [], '6' => [$this->link('40', false, false, null)], '7' => []];
        $this->fakeAgent([
            ['action' => 'link', 'menu_category' => 'Pizzas', 'group' => 'Sauces'],
        ]);

        $this->ask('add all the sauces to every pizza');

        // Pizzas section = items 5 and 6. Sauces = 40 (ketchup), 41 (garlic). Item 7 (cola) is a drink.
        foreach (['5' => ['40', '41'], '6' => ['41']] as $item => $ingredients) {
            foreach ($ingredients as $ingredient) {
                Http::assertSent(fn (Request $r) => $r->method() === 'POST'
                    && $r->url() === self::CASHIER.'/items/'.$item.'/ingredients'
                    && $r['ingredient_id'] === $ingredient);
            }
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/items/7/'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::CASHIER.'/items/6/ingredients' && $r['ingredient_id'] === '40');
    }

    public function test_exact_meal_name_wins_over_partial_matches(): void
    {
        $this->links = ['8' => [], '9' => []];
        $this->fakeAgent([
            ['action' => 'link', 'item' => 'Espresso', 'ingredient' => 'Oregano'],
        ]);

        $this->ask('link oregano to espresso');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::CASHIER.'/items/8/ingredients');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::CASHIER.'/items/9/ingredients');
    }

    public function test_read_item_options_shows_group_default_and_price(): void
    {
        $this->links = ['5' => [$this->link('30', true, true, 3), $this->link('40', false, false, null)]];
        $toolResult = null;
        $this->fakeAgent(null, 'read_item_options', ['item' => 'Margherita'], $toolResult);

        $this->ask('what options does the margherita have?');

        $this->assertNotNull($toolResult);
        $decoded = json_decode((string) $toolResult, true);
        $options = $decoded['meals'][0]['options'];
        $this->assertSame('Extra cheese', $options[0]['name']);
        $this->assertSame('Toppings', $options[0]['group']);
        $this->assertTrue($options[0]['default']);
        $this->assertTrue($options[0]['extra_fee']);
        $this->assertSame(3.0, (float) $options[0]['custom_fee']);
        $this->assertSame('Sauces', $options[1]['group']);
        $this->assertFalse($options[1]['extra_fee']);
    }

    public function test_naming_guide_prefers_local_names(): void
    {
        $guide = MenuNaming::guide();

        $this->assertStringContainsString('Double espresso = اسبريسو دبل / אספרסו כפול', $guide);
        $this->assertStringContainsString('Instant coffee = نسكافيه / נס קפה', $guide);
        $this->assertStringContainsString('not "إسبرسو مزدوج"', $guide);
        foreach (MenuNaming::GLOSSARY as $english => [$arabic, $hebrew]) {
            $this->assertDoesNotMatchRegularExpression('/[\x{0590}-\x{05FF}]/u', $arabic, $english.' Arabic has Hebrew letters');
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $hebrew, $english.' Hebrew has Arabic letters');
        }
    }

    public function test_chat_and_photo_reading_prompts_carry_the_naming_guide(): void
    {
        config(['openai.api_key' => 'test-key']);
        $prompts = [];
        Http::fake(function (Request $request) use (&$prompts) {
            if (str_contains($request->url(), 'chat/completions')) {
                $prompts[] = json_encode($request->data()['messages'] ?? [], JSON_UNESCAPED_UNICODE);

                return Http::response(['choices' => [['message' => ['content' => '{"categories":[]}']]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->actingAs($this->user())
            ->withSession($this->kamanSession())
            ->post(route('form.menu-agent.chat'), [
                'messages' => [['role' => 'user', 'content' => 'read this menu']],
                'attachments' => [UploadedFile::fake()->image('menu.jpg', 40, 40)],
            ])->streamedContent();

        $this->assertGreaterThanOrEqual(2, count($prompts));
        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('اسبريسو دبل', $prompt);
        }
    }

    public function test_reads_excel_word_html_and_csv_menus_as_text(): void
    {
        $seen = $this->ocrPromptsFor([
            $this->xlsx('menu.xlsx', [['Name', 'Price'], ['Cappuccino', '15'], ['Espresso', '10']]),
            $this->docx('menu.docx', ['Hot drinks', 'Latte 14']),
            UploadedFile::fake()->createWithContent('menu.html', '<html><style>.x{}</style><body><h1>Desserts</h1><p>Waffle&nbsp;30</p><script>alert(1)</script></body></html>'),
            UploadedFile::fake()->createWithContent('menu.tsv', "Toast\t25\n"),
        ]);

        $this->assertStringContainsString("Cappuccino\t15", $seen);
        $this->assertStringContainsString('Latte 14', $seen);
        $this->assertStringContainsString('Waffle', $seen);
        $this->assertStringNotContainsString('alert(1)', $seen);
        $this->assertStringContainsString("Toast\t25", $seen);
    }

    public function test_bmp_and_gif_photos_are_converted_to_jpeg_for_vision(): void
    {
        $bmp = $this->gdImage('menu.bmp', 'imagebmp');
        $gif = $this->gdImage('menu.gif', 'imagegif');

        $images = [];
        $this->ocrPromptsFor([$bmp, $gif], $images);

        $this->assertCount(2, $images);
        foreach ($images as $url) {
            $this->assertStringStartsWith('data:image/jpeg;base64,', $url);
        }
    }

    public function test_unconvertible_image_says_so_instead_of_vanishing(): void
    {
        if (class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick converts HEIC here.');
        }

        $seen = $this->ocrPromptsFor([
            UploadedFile::fake()->createWithContent('menu.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1"),
        ]);

        $this->assertStringContainsString('Image menu.heic is in a format this server cannot convert', $seen);
    }

    public function test_pdf_text_layer_is_read_when_pdftotext_exists(): void
    {
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null';
        if (trim((string) shell_exec($finder)) === '') {
            $this->markTestSkipped('pdftotext is not installed here.');
        }

        $seen = $this->ocrPromptsFor([
            UploadedFile::fake()->createWithContent('menu.pdf', $this->pdf(['Espresso 10.00', 'Cappuccino 15.00'])),
        ]);

        $this->assertStringContainsString('Text layer of PDF menu.pdf', $seen);
        $this->assertStringContainsString('Cappuccino 15.00', $seen);
    }

    /**
     * @param  list<array<string, mixed>>|null  $operations
     * @param  array<string, mixed>  $readArguments
     */
    private function fakeAgent(?array $operations, string $tool = 'edit_item_options', array $readArguments = [], ?string &$toolResult = null): void
    {
        config(['openai.api_key' => 'test-key']);
        $arguments = $operations !== null ? ['operations' => $operations] : $readArguments;
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls, &$toolResult, $tool, $arguments) {
            $url = $request->url();
            if (str_contains($url, 'chat/completions')) {
                $calls++;
                if ($calls === 1) {
                    return Http::response(['choices' => [['message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => $tool, 'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE)],
                        ]],
                    ]]]], 200);
                }
                foreach ($request->data()['messages'] ?? [] as $message) {
                    if (($message['role'] ?? '') === 'tool') {
                        $toolResult = (string) $message['content'];
                    }
                }

                return Http::response(['choices' => [['message' => ['content' => 'RESULT '.$toolResult]]]], 200);
            }

            return $this->kaman($request);
        });
    }

    private function kaman(Request $request): \GuzzleHttp\Promise\PromiseInterface
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $method = $request->method();

        if ($method === 'GET' && $path === '/api/manager/items') {
            return Http::response(['data' => [
                ['id' => 5, 'name_en' => 'Margherita', 'name_ar' => 'مارغريتا', 'name_he' => 'מרגריטה', 'category_id' => 1, 'price' => 40],
                ['id' => 6, 'name_en' => 'Pepperoni Pizza', 'name_ar' => 'بيتسا بيبروني', 'name_he' => 'פיצה פפרוני', 'category_id' => 1, 'price' => 45],
                ['id' => 7, 'name_en' => 'Cola', 'name_ar' => 'كولا', 'name_he' => 'קולה', 'category_id' => 2, 'price' => 8],
                ['id' => 8, 'name_en' => 'Espresso', 'name_ar' => 'اسبريسو', 'name_he' => 'אספרסו', 'category_id' => 2, 'price' => 10],
                ['id' => 9, 'name_en' => 'Double Espresso', 'name_ar' => 'اسبريسو دبل', 'name_he' => 'אספרסו כפול', 'category_id' => 2, 'price' => 12],
            ]], 200);
        }
        if ($method === 'GET' && $path === '/api/manager/categories') {
            return Http::response(['data' => [
                ['id' => 1, 'name_en' => 'Pizzas', 'name_ar' => 'بيتسا', 'name_he' => 'פיצות'],
                ['id' => 2, 'name_en' => 'Drinks', 'name_ar' => 'مشروبات', 'name_he' => 'משקאות'],
            ]], 200);
        }
        if ($method === 'GET' && $path === '/api/cashier/ingredient-categories') {
            return Http::response(['data' => [
                ['id' => 10, 'name_en' => 'Toppings', 'name_ar' => 'إضافات', 'name_he' => 'תוספות'],
                ['id' => 11, 'name_en' => 'Sauces', 'name_ar' => 'صلصات', 'name_he' => 'רטבים'],
            ]], 200);
        }
        if ($method === 'GET' && $path === '/api/cashier/ingredients') {
            return Http::response(['data' => [
                ['id' => 30, 'name_en' => 'Extra cheese', 'name_ar' => 'جبنة إضافية', 'name_he' => 'תוספת גבינה', 'price' => 3, 'category_id' => 10],
                ['id' => 31, 'name_en' => 'Olives', 'name_ar' => 'زيتون', 'name_he' => 'זיתים', 'price' => 2.5, 'category_id' => 10],
                ['id' => 32, 'name_en' => 'Oregano', 'name_ar' => 'زعتر', 'name_he' => 'אורגנו', 'price' => 0, 'category_id' => 10],
                ['id' => 40, 'name_en' => 'Ketchup', 'name_ar' => 'كاتشب', 'name_he' => 'קטשופ', 'price' => 0, 'category_id' => 11],
                ['id' => 41, 'name_en' => 'Garlic sauce', 'name_ar' => 'ثومية', 'name_he' => 'רוטב שום', 'price' => 0, 'category_id' => 11],
            ]], 200);
        }
        if (preg_match('#^/api/cashier(/items/(\d+)/ingredients(?:/(\d+))?)$#', $path, $m) === 1) {
            if ($method === 'GET') {
                return Http::response(['data' => $this->links[$m[2]] ?? []], 200);
            }
            if ($method === 'DELETE') {
                $status = $this->deleteStatus[$m[1]] ?? 200;

                return Http::response($status === 200 ? ['success' => true] : ['message' => 'Method not allowed'], $status);
            }

            return Http::response(['data' => ['id' => 99]], $method === 'POST' ? 201 : 200);
        }

        return Http::response(['data' => []], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function link(string $ingredientId, bool $default, bool $extra, ?float $fee): array
    {
        return [
            'id' => 900 + (int) $ingredientId,
            'ingredient_id' => (int) $ingredientId,
            'default_ingredient' => $default,
            'extra_fee' => $extra,
            'custom_fee' => $fee,
        ];
    }

    private function ask(string $text): string
    {
        $response = $this->actingAs($this->user())
            ->withSession($this->kamanSession())
            ->post(route('form.menu-agent.chat'), [
                'messages' => [['role' => 'user', 'content' => $text]],
            ]);
        $response->assertOk();

        return $response->streamedContent();
    }

    /**
     * Upload files and return every text block sent to the menu-reading model.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $images  image data URLs sent to the model
     */
    private function ocrPromptsFor(array $files, array &$images = []): string
    {
        config(['openai.api_key' => 'test-key']);
        $texts = [];
        Http::fake(function (Request $request) use (&$texts, &$images) {
            if (str_contains($request->url(), 'chat/completions')) {
                foreach ($request->data()['messages'] ?? [] as $message) {
                    $content = $message['content'] ?? '';
                    foreach (is_array($content) ? $content : [['type' => 'text', 'text' => $content]] as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $texts[] = (string) $block['text'];
                        } elseif (($block['type'] ?? '') === 'image_url' && ($message['role'] ?? '') === 'user'
                            && str_contains((string) json_encode($request->data()['messages'][0] ?? []), 'extract complete restaurant menus')) {
                            $images[] = (string) $block['image_url']['url'];
                        }
                    }
                }

                return Http::response(['choices' => [['message' => ['content' => '{"categories":[]}']]]], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->actingAs($this->user())
            ->withSession($this->kamanSession())
            ->post(route('form.menu-agent.chat'), [
                'messages' => [['role' => 'user', 'content' => 'read these menus']],
                'attachments' => $files,
            ])->streamedContent();

        return implode("\n", $texts);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function xlsx(string $name, array $rows): UploadedFile
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_numeric($value)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $strings[] = $value;
                    $cells .= '<c r="'.$ref.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }
        $shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(static fn (string $s): string => '<si><t>'.htmlspecialchars($s).'</t></si>', $strings)).'</sst>';
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>';

        return $this->zipUpload($name, ['xl/sharedStrings.xml' => $shared, 'xl/worksheets/sheet1.xml' => $sheet]);
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private function docx(string $name, array $paragraphs): UploadedFile
    {
        $body = implode('', array_map(static fn (string $p): string => '<w:p><w:r><w:t>'.htmlspecialchars($p).'</w:t></w:r></w:p>', $paragraphs));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';

        return $this->zipUpload($name, ['word/document.xml' => $xml]);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipUpload(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    private function gdImage(string $name, string $writer): UploadedFile
    {
        $image = imagecreatetruecolor(60, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 240, 230));
        ob_start();
        $writer($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * @param  list<string>  $lines
     */
    private function pdf(array $lines): string
    {
        $stream = "BT /F1 18 Tf 50 750 Td 24 TL\n";
        foreach ($lines as $line) {
            $stream .= '('.$line.") Tj T*\n";
        }
        $stream .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    /**
     * @return array<string, string>
     */
    private function kamanSession(): array
    {
        return [
            'full_ai_kaman_token' => 'kaman-token',
            'full_ai_kaman_base_url' => self::MANAGER,
        ];
    }

    private function user(): User
    {
        $this->seed(WorkspaceUserSeeder::class);

        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }
}
