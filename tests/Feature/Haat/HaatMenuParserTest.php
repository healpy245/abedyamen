<?php

declare(strict_types=1);

namespace Tests\Feature\Haat;

use App\Services\Haat\HaatMenuParser;
use Tests\TestCase;

final class HaatMenuParserTest extends TestCase
{
    private const FULL_SAMPLE = 'C:\\Users\\win\\Documents\\Haat Integration\\Curosor\\menu_response.json';

    public function test_parser_ignores_top_selling_and_footer_and_keeps_groups(): void
    {
        $parsed = (new HaatMenuParser)->parseFile(base_path('tests/Fixtures/Haat/menu_mini.json'));

        $this->assertCount(1, $parsed['categories']);
        $this->assertSame('وجبات زاتشية وجديدة!', $parsed['categories'][0]['name_ar']);
        $this->assertSame('New ZACHI meals!', $parsed['categories'][0]['name_en']);
        $this->assertSame('New ZACHI meals!', $parsed['categories'][0]['name_he']);
        $this->assertSame(1, $parsed['categories'][0]['order_index']);

        $this->assertCount(2, $parsed['meals']);

        $salad = $parsed['meals'][0];
        $this->assertSame('946663', $salad['haat_id']);
        $this->assertSame('38.00', $salad['price']);
        $this->assertNotEmpty($salad['groups']);
        $this->assertSame(0, $salad['groups'][0]['min']);
        $this->assertSame(0, $salad['groups'][0]['max']);
        $this->assertSame('Fixed', $salad['groups'][0]['contents'][0]['view_type']);
        $this->assertTrue($salad['groups'][0]['contents'][0]['selected_by_default']);
        $this->assertArrayNotHasKey('hashedPrice', $salad);
        $this->assertArrayNotHasKey('hashed_price', $salad);

        $mess = $parsed['meals'][1];
        $this->assertFalse($mess['is_available']);
        $this->assertCount(2, $mess['groups']);
        $choice = $mess['groups'][1];
        $this->assertSame('اختيار', $choice['title']);
        $this->assertSame(1, $choice['min']);
        $this->assertSame(1, $choice['max']);
        $this->assertTrue($choice['is_limited']);
        $this->assertSame('Radio', $choice['contents'][0]['view_type']);
        $this->assertFalse($choice['contents'][0]['selected_by_default']);

        $titles = array_column($parsed['unique_ingredient_categories'], 'name_en', 'name_ar');
        $this->assertSame('Salad ingredients', $titles['مكونات السلطة']);
        $this->assertSame('Choice', $titles['اختيار']);

        $byTitle = [];
        foreach ($parsed['unique_ingredient_categories'] as $row) {
            $byTitle[$row['title']] = $row;
        }
        $this->assertSame('one_option', $byTitle['اختيار']['type']);
        $this->assertTrue($byTitle['اختيار']['must_pick']);
        $this->assertSame(1, $byTitle['اختيار']['min_options']);
        $this->assertSame(1, $byTitle['اختيار']['max_options']);
        $this->assertSame('multi_option', $byTitle['مكونات السلطة']['type']);
        $this->assertFalse($byTitle['مكونات السلطة']['must_pick']);
    }

    public function test_same_ingredient_name_in_the_same_group_is_deduplicated(): void
    {
        $parsed = (new HaatMenuParser)->parse(json_encode([
            'sections' => [[
                'type' => 'Categories',
                'id' => 1,
                'name' => 'سلطات',
                'nameEnglish' => 'Salads',
                'restaurantItems' => [[
                    'id' => 10,
                    'menuItemType' => 'Meal',
                    'name' => 'سلطة',
                    'nameEnglish' => 'Salad',
                    'price' => ['finalPrice' => 20],
                    'availability' => ['isAvailable' => true],
                    'addonGroups' => [[
                        'id' => 20,
                        'title' => 'مكونات السلطة',
                        'min' => 0,
                        'max' => 0,
                        'mealContents' => [
                            ['id' => 100, 'name' => 'خس', 'price' => 0, 'viewType' => 'Checkbox'],
                            ['id' => 101, 'name' => 'خس', 'price' => 0, 'viewType' => 'Checkbox'],
                            ['id' => 102, 'name' => 'خس', 'price' => 2, 'viewType' => 'Checkbox'],
                            ['id' => 103, 'name' => 'بندورة', 'price' => 0, 'viewType' => 'Checkbox'],
                        ],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertCount(3, $parsed['unique_ingredients']);
        $zeroLettuce = collect($parsed['unique_ingredients'])->first(
            fn (array $row) => $row['name'] === 'خس' && $row['price'] === '0.00'
        );
        $this->assertNotNull($zeroLettuce);
        $this->assertEqualsCanonicalizing(['100', '101'], $zeroLettuce['haat_ids']);
        $this->assertCount(1, $parsed['unique_ingredient_categories']);
    }

    public function test_full_sample_has_twenty_four_category_sections(): void
    {
        if (! is_file(self::FULL_SAMPLE)) {
            $this->markTestSkipped('Full HAAT menu_response.json is not on this machine.');
        }

        $parsed = (new HaatMenuParser)->parseFile(self::FULL_SAMPLE);

        $this->assertCount(24, $parsed['categories']);
        $this->assertGreaterThan(0, count($parsed['meals']));
        $this->assertGreaterThan(0, count($parsed['unique_ingredient_categories']));
        $this->assertGreaterThan(0, count($parsed['unique_ingredients']));

        $withGroups = array_values(array_filter($parsed['meals'], fn (array $meal) => $meal['groups'] !== []));
        $this->assertNotEmpty($withGroups);
        $group = $withGroups[0]['groups'][0];
        $this->assertArrayHasKey('min', $group);
        $this->assertArrayHasKey('max', $group);
        $this->assertArrayHasKey('view_type', $group['contents'][0]);
        $this->assertArrayHasKey('selected_by_default', $group['contents'][0]);

        $encoded = json_encode($parsed);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('hashedPrice', $encoded);
    }
}
