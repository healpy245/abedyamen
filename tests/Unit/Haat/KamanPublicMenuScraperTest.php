<?php

declare(strict_types=1);

namespace Tests\Unit\Haat;

use App\Services\Haat\KamanPublicMenuScraper;
use Tests\TestCase;

final class KamanPublicMenuScraperTest extends TestCase
{
    public function test_parses_public_menu_html(): void
    {
        $html = <<<'HTML'
<button type="button" class="z-chip menu-chip" data-target="section-cat-1">
                            مقبلات
                        </button>
<section class="z-menu-section" id="section-cat-1">
<li class="menu-item-row"
                                    data-name="هوم فرايز"
                                    data-cat="cat-1">
                                    <div class="z-item showItem cursor-pointer"
                                         data-id="item-1"
                                         data-name="هوم فرايز"
                                         data-description="وصف"
                                         data-price="25"
                                         data-type="sandwich"
                                         data-image="https://gcp.kaman.rest/img.jpg">
<script>
        itemsIngredients = {"item-1":{"خيارات":[{"id":"ing-1","category_id":"ic-1","category_type":"one_option","category_name":"خيارات","name":"تشيبس","price":0,"image":null,"default_ingredient":1,"extra_fee":0,"extra_fee_cost":0,"allow_multiple":false,"max_options":null,"min_options":null,"must_pick":0}]}};
</script>
HTML;

        $parsed = (new KamanPublicMenuScraper)->parseHtml($html);

        $this->assertCount(1, $parsed['categories']);
        $this->assertSame('مقبلات', $parsed['categories'][0]['name_ar']);
        $this->assertCount(1, $parsed['meals']);
        $this->assertSame('هوم فرايز', $parsed['meals'][0]['name_ar']);
        $this->assertSame('https://gcp.kaman.rest/img.jpg', $parsed['meals'][0]['image_url']);
        $this->assertSame('25.00', $parsed['meals'][0]['price']);
        $this->assertCount(1, $parsed['unique_ingredients']);
        $this->assertSame('ing-1', $parsed['unique_ingredients'][0]['haat_id']);
        $this->assertTrue($parsed['meals'][0]['groups'][0]['contents'][0]['selected_by_default']);
    }
}
