<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Finance\StorageItem;
use App\Models\Sales\SalesCatalogItem;
use App\Models\Sales\SalesQuoteItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The storage page is "Storage, services & features": devices and stock, one-time services, and the
 * monthly subscription with its features. Everything a quote is priced from.
 */
class StorageServicesFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake(['https://kaman.rest/*' => Http::response(['data' => ['companies' => [], 'areas' => []]])]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_the_page_has_a_tab_for_storage_services_and_features(): void
    {
        $this->actingAs($this->admin)->get(route('workspace.finance.storage'))
            ->assertOk()
            ->assertSee(__('finance.storage.title'))
            ->assertSee(__('finance.storage.tabs.services'))
            ->assertSee(__('finance.storage.tabs.features'))
            ->assertSee('מגירת כסף')
            ->assertDontSee('בניית תפריט');

        $services = $this->get(route('workspace.finance.storage', ['tab' => 'services']))
            ->assertOk()
            ->assertSee('בניית תפריט')
            ->assertSee('הדרכת CRM בזום')
            ->assertSee('data-category="service"', false);
        // Devices stay on their own tab (they still show in the recent stock movements).
        $this->assertNotContains('מגירת כסף', $services->viewData('items')->pluck('name')->all());

        $this->get(route('workspace.finance.storage', ['tab' => 'features']))
            ->assertOk()
            ->assertSee(SalesCatalogItem::query()->where('kind', 'base')->firstOrFail()->name)
            ->assertSee('data-fin-feature-new', false);
    }

    public function test_service_prices_change_on_the_services_tab(): void
    {
        $menu = StorageItem::query()->where('name', 'בניית תפריט')->firstOrFail();

        $this->actingAs($this->admin)->patch(route('workspace.finance.storage.update', $menu), [
            'name' => 'בניית תפריט',
            'category' => 'service',
            'unit_price' => 650,
        ])->assertRedirect(route('workspace.finance.storage', ['tab' => 'services']));
        $this->assertSame(650.0, (float) $menu->fresh()->unit_price);

        $this->post(route('workspace.finance.storage.store'), [
            'name' => 'Website build',
            'category' => 'service',
            'unit_price' => 900,
        ])->assertRedirect(route('workspace.finance.storage', ['tab' => 'services']));
        $this->assertFalse(StorageItem::query()->where('name', 'Website build')->firstOrFail()->track_stock);
    }

    public function test_finance_adds_edits_and_removes_features(): void
    {
        $this->actingAs($this->admin)->post(route('workspace.finance.storage.features.store'), [
            'name' => 'מועדון לקוחות',
            'name_en' => 'Loyalty club',
            'price' => 120,
            'billing' => 'monthly',
            'bullets' => "נקודות\nקופונים\n",
            'popular' => '1',
        ])->assertRedirect(route('workspace.finance.storage', ['tab' => 'features']));

        $feature = SalesCatalogItem::query()->where('name', 'מועדון לקוחות')->firstOrFail();
        $this->assertSame('feature', $feature->kind);
        $this->assertSame(['נקודות', 'קופונים'], $feature->bullets);
        $this->assertTrue($feature->popular);

        // Agents pick it up straight away.
        $agent = User::factory()->create(['is_admin' => false]);
        $catalog = $this->actingAs($agent)->get(route('workspace.agents.sales'))->assertOk()->viewData('catalog');
        $this->assertContains($feature->id, array_column($catalog['feature'], 'id'));

        $this->actingAs($this->admin)->patch(route('workspace.finance.storage.features.update', $feature), [
            'name' => 'מועדון לקוחות',
            'price' => 150,
            'billing' => 'monthly',
        ])->assertRedirect();
        $this->assertSame(150.0, (float) $feature->fresh()->price);

        // The base subscription's price changes too, and it stays monthly.
        $base = SalesCatalogItem::query()->where('kind', 'base')->firstOrFail();
        $this->patch(route('workspace.finance.storage.features.update', $base), ['name' => $base->name, 'price' => 349, 'billing' => 'one_time'])->assertRedirect();
        $this->assertSame(349.0, (float) $base->fresh()->price);
        $this->assertSame('monthly', $base->fresh()->billing);
        $this->delete(route('workspace.finance.storage.features.destroy', $base))->assertNotFound();

        // A feature on a quote is hidden, not deleted.
        $used = SalesCatalogItem::query()->where('kind', 'feature')->whereKeyNot($feature->id)->firstOrFail();
        SalesQuoteItem::query()->forceCreate([
            'sales_quote_id' => \App\Models\Sales\SalesQuote::query()->forceCreate(['number' => 5001, 'status' => 'draft', 'lang' => 'he'])->id,
            'kind' => 'feature', 'sales_catalog_item_id' => $used->id, 'name' => $used->name,
            'quantity' => 1, 'unit_price' => 10, 'billing' => 'monthly', 'line_total' => 10,
        ]);
        $this->delete(route('workspace.finance.storage.features.destroy', $used))->assertRedirect();
        $this->assertFalse($used->fresh()->active);
        $this->delete(route('workspace.finance.storage.features.destroy', $feature))->assertRedirect();
        $this->assertNull($feature->fresh());
    }

    public function test_only_finance_manages_the_price_list(): void
    {
        $agent = User::factory()->create(['is_admin' => false]);
        $this->actingAs($agent)->post(route('workspace.finance.storage.features.store'), ['name' => 'X', 'price' => 1, 'billing' => 'monthly']);
        $this->assertFalse(SalesCatalogItem::query()->where('name', 'X')->exists());
    }
}
