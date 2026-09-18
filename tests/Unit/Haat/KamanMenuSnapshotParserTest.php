<?php

declare(strict_types=1);

namespace Tests\Unit\Haat;

use App\Services\Haat\KamanMenuSnapshotParser;
use Tests\TestCase;

final class KamanMenuSnapshotParserTest extends TestCase
{
    public function test_parses_categories_meals_ingredients_and_links(): void
    {
        $parsed = (new KamanMenuSnapshotParser)->parse([
            'categories' => [[
                'id' => 1,
                'name_ar' => 'برجر',
                'name_en' => 'Burgers',
                'name_he' => 'המבורגר',
                'order_index' => 2,
            ]],
            'items' => [[
                'id' => 10,
                'name_ar' => 'برجر لحم',
                'name_en' => 'Beef burger',
                'name_he' => 'המבורגר בקר',
                'description_ar' => 'وصف',
                'price' => '38.00',
                'category_id' => 1,
                'image' => 'https://cdn.example/burger.jpg',
                'status' => '1',
            ]],
            'ingredient_categories' => [[
                'id' => 5,
                'name_ar' => 'إضافات',
                'name_en' => 'Addons',
                'type' => 'multi_option',
                'min_options' => 0,
                'max_options' => 5,
                'must_pick' => false,
            ]],
            'ingredients' => [[
                'id' => 100,
                'name_ar' => 'خس',
                'name_en' => 'Lettuce',
                'price' => 2,
                'category_id' => 5,
                'allow_multiple' => true,
                'image_url' => 'https://cdn.example/lettuce.jpg',
            ]],
            'item_links' => [
                '10' => [
                    '100' => [
                        'ingredient_id' => '100',
                        'default_ingredient' => true,
                        'extra_fee' => true,
                        'custom_fee' => 2,
                    ],
                ],
            ],
        ], 'https://gcp.kaman.rest/api/manager');

        $this->assertCount(1, $parsed['categories']);
        $this->assertSame('1', $parsed['categories'][0]['haat_id']);
        $this->assertSame('Burgers', $parsed['categories'][0]['name_en']);
        $this->assertCount(1, $parsed['meals']);
        $this->assertSame('https://cdn.example/burger.jpg', $parsed['meals'][0]['image_url']);
        $this->assertSame('1', $parsed['meals'][0]['category_haat_id']);
        $this->assertCount(1, $parsed['meals'][0]['groups']);
        $this->assertSame('100', $parsed['meals'][0]['groups'][0]['contents'][0]['haat_id']);
        $this->assertTrue($parsed['meals'][0]['groups'][0]['contents'][0]['selected_by_default']);
        $this->assertCount(1, $parsed['unique_ingredient_categories']);
        $this->assertSame('إضافات', $parsed['unique_ingredient_categories'][0]['title']);
        $this->assertCount(1, $parsed['unique_ingredients']);
        $this->assertSame('100', $parsed['unique_ingredients'][0]['haat_id']);
        $this->assertSame('إضافات', $parsed['unique_ingredients'][0]['category_title']);
    }
}
