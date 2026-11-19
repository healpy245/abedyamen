<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\SupportDevice;
use App\Services\KamanSupport\SupportDeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RestaurantChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_restaurant_search_uses_the_overview_restaurant_list(): void
    {
        Cache::put('kaman.rest.companies.v2', [
            ['id' => 'c-2', 'name' => 'Zaatar House', 'subdomain' => 'zaatar', 'logo' => 'z.png'],
            ['id' => 'c-1', 'name' => 'Asado Grill', 'subdomain' => 'asado', 'logo' => ''],
        ]);
        // A tablet that reports in with another spelling keeps its own value so device filters still match.
        SupportDevice::query()->forceCreate(['restaurant' => 'Asado', 'device_id' => 'tab-1', 'restaurant_name' => 'old name']);

        $choices = app(SupportDeviceSessionService::class)->restaurantChoices();

        $this->assertSame([
            ['value' => 'Asado', 'label' => 'Asado Grill', 'logo' => ''],
            ['value' => 'zaatar', 'label' => 'Zaatar House', 'logo' => 'z.png'],
        ], $choices);
    }

    public function test_it_falls_back_to_reported_restaurants_when_kaman_is_unreachable(): void
    {
        Cache::forget('kaman.rest.companies.v2');
        \Illuminate\Support\Facades\Http::fake(['kaman.rest/*' => \Illuminate\Support\Facades\Http::response('', 500)]);
        SupportDevice::query()->forceCreate(['restaurant' => 'asado', 'device_id' => 'tab-1', 'restaurant_name' => 'Asado']);

        $this->assertSame(
            [['value' => 'asado', 'label' => 'Asado']],
            app(SupportDeviceSessionService::class)->restaurantChoices(),
        );
    }
}
