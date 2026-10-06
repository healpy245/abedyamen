<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use App\Services\KamanSupport\SupportDeviceSessionService;
use App\Services\KamanSupport\SupportTicketService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanSupportConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    private function yamen(): User
    {
        return User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function submitTicket(array $overrides = []): SupportTicket
    {
        $payload = array_merge([
            'clientTicketId' => 'KAM-XSS001',
            'summary' => 'طلب 45 لا يطبع',
            'details' => 'تفاصيل عربية',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman',
            'cashierName' => 'Ali',
            'deviceId' => 'tablet-1',
            'locale' => 'ar',
            'conversation' => ['user: <script>alert(1)</script>'],
            'diagnosis' => ['cause' => 'noMatchingKitchenType', 'printer' => 'جريل'],
            'printers' => [['name' => 'جريل', 'ip' => '10.0.0.3:9100']],
        ], $overrides);

        return app(SupportTicketService::class)->submitFromPos($payload)['ticket'];
    }

    public function test_guests_are_sent_to_workspace_login(): void
    {
        $this->get('/kaman-support/')->assertRedirect('/login');
    }

    public function test_only_yamen_can_open_the_console(): void
    {
        $ticket = $this->submitTicket();

        $this->actingAs($this->yamen())
            ->get('/kaman-support/')
            ->assertOk()
            ->assertSee('KAM-XSS001', false)
            ->assertSee('طلب 45 لا يطبع', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.show', $ticket))
            ->assertOk()
            ->assertSee('جريل', false)
            ->assertSee('noMatchingKitchenType', false)
            ->assertSee(__('kaman-support.live_coming'))
            ->assertSee(__('kaman-support.nav.live'), false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->get('/kaman-support/')
            ->assertForbidden();

        $this->actingAs(User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail())
            ->get('/kaman-support/')
            ->assertForbidden();
    }

    public function test_worker_can_change_status_and_add_a_note(): void
    {
        $ticket = $this->submitTicket();

        $this->actingAs($this->yamen())
            ->patch(route('kaman-support.update', $ticket), [
                'status' => 'in_progress',
                'assignee' => 'Yamen',
                'note' => 'Working the grill printer',
            ])
            ->assertRedirect(route('kaman-support.show', $ticket));

        $this->actingAs($this->yamen())
            ->post(route('kaman-support.events.store', $ticket), [
                'type' => 'note',
                'message' => 'Asked them to reprint order 45',
            ])
            ->assertRedirect(route('kaman-support.show', $ticket));

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.show', $ticket))
            ->assertOk()
            ->assertSee('Working the grill printer', false)
            ->assertSee('Asked them to reprint order 45', false)
            ->assertSee(__('kaman-support.status.in_progress'));
    }

    public function test_yamen_can_open_live_pos_log_page(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $tablet = $sessions->heartbeat([
            'deviceId' => 'tablet-live',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'cashierName' => 'Ali',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'appVersion' => '1.3.15+38',
            'loggedIn' => true,
            'logs' => [[
                'id' => 'err-1',
                'level' => 'error',
                'source' => 'print',
                'message' => 'kitchen printer timeout',
            ], [
                'id' => 'api-1',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 kitchen timeout',
            ], [
                'id' => 'api-ok',
                'level' => 'debug',
                'source' => 'api',
                'message' => 'GET /menu 200 ok',
            ], [
                'id' => 'tap-1',
                'level' => 'info',
                'source' => 'tap',
                'message' => 'tapped Save order',
            ]],
        ]);
        $other = $sessions->heartbeat([
            'deviceId' => 'tablet-bar',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Bar',
            'cashierName' => 'Sara',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'loggedIn' => true,
            'logs' => [[
                'id' => 'err-2',
                'level' => 'error',
                'source' => 'flutter',
                'message' => 'null check on bar tablet',
            ]],
        ]);

        $this->actingAs($this->yamen())
            ->get('/kaman-support/liveview')
            ->assertOk()
            ->assertSee('Kaman Grill', false)
            ->assertSee('Kaman Bar', false)
            ->assertSee(__('kaman-support.live_title'));

        $jerusalemHour = now()->timezone('Asia/Jerusalem')->format('H:i');

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live'))
            ->assertOk()
            ->assertSee('Kaman Grill', false)
            ->assertSee('Kaman Bar', false)
            ->assertSee('Ali', false)
            ->assertSee(__('kaman-support.kind_tablet'), false)
            ->assertSee(__('kaman-support.live_channel'), false)
            ->assertSee(__('kaman-support.live_title'))
            ->assertSee(__('kaman-support.view_all_logs'), false)
            ->assertSee(__('kaman-support.view_api'), false)
            ->assertSee(__('kaman-support.source_flutter'), false)
            ->assertSee(__('kaman-support.jerusalem_time'), false)
            ->assertSee('kitchen printer timeout', false)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('GET /menu 200 ok', false)
            ->assertSee('tapped Save order', false)
            ->assertSee('/orders', false)
            ->assertSee('500', false)
            ->assertSee($jerusalemHour, false)
            ->assertSee('null check on bar tablet', false)
            ->assertSee(trans('kaman-support.failed_on_device', ['count' => 1]), false)
            ->assertDontSee('http-equiv="refresh"', false)
            ->assertDontSee('tap</option>', false)
            ->assertDontSee('print</option>', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', ['source' => 'flutter', 'view' => 'all']))
            ->assertOk()
            ->assertSee('null check on bar tablet', false)
            ->assertDontSee('tapped Save order', false);

        $from = now()->timezone('Asia/Jerusalem')->subHour()->format('Y-m-d\TH:i');
        $to = now()->timezone('Asia/Jerusalem')->addHour()->format('Y-m-d\TH:i');
        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', ['view' => 'all', 'from' => $from, 'to' => $to, 'source' => 'api']))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('GET /menu 200 ok', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', ['view' => 'all']))
            ->assertOk()
            ->assertSee('kitchen printer timeout', false)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('GET /menu 200 ok', false)
            ->assertSee('tapped Save order', false)
            ->assertSee('null check on bar tablet', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', ['view' => 'api']))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertDontSee('kitchen printer timeout', false)
            ->assertDontSee('GET /menu 200 ok', false)
            ->assertDontSee('tapped Save order', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live.feed', ['view' => 'all']))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('hints.failedApi', 1)
            ->assertJsonPath('live.0.failedCount', 1)
            ->assertSee('tapped Save order', false)
            ->assertSee('GET /orders 500 kitchen timeout', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device', $tablet))
            ->assertOk()
            ->assertSee('tablet-live', false)
            ->assertSee(__('kaman-support.search_printers'), false)
            ->assertSee(__('kaman-support.saved_printers'), false)
            ->assertSee(__('kaman-support.sync_kitchen_api'), false)
            ->assertSee(__('kaman-support.live_channel_hint'), false)
            ->assertSee(__('kaman-support.view_all_logs'), false)
            ->assertSee(__('kaman-support.device_live_logs'), false)
            ->assertSee('kitchen printer timeout', false)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('GET /menu 200 ok', false)
            ->assertSee('tapped Save order', false)
            ->assertSee('data-live-console', false)
            ->assertDontSee('http-equiv="refresh"', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device', [$tablet, 'view' => 'api']))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertDontSee('kitchen printer timeout', false)
            ->assertDontSee('tapped Save order', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device.feed', $tablet))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('device.deviceId', 'tablet-live')
            ->assertJsonPath('device.watch', true)
            ->assertJsonPath('logs.0.source', 'tap')
            ->assertSee('tapped Save order', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device.feed', [$tablet, 'view' => 'api']))
            ->assertOk()
            ->assertJsonPath('hints.failedApi', 1)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertDontSee('tapped Save order', false);

        $this->actingAs($this->yamen())
            ->postJson(route('kaman-support.device.commands', $tablet), [
                'name' => 'test_print',
                'args' => ['printer' => 'receipt'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'test_print');

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.live'))
            ->assertForbidden();
    }

    public function test_failed_requests_page_filters_by_restaurant_date_and_type(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $sessions->heartbeat([
            'deviceId' => 'tablet-grill',
            'restaurant' => 'gcp',
            'restaurantName' => 'GCP Kaman',
            'cashierName' => 'Ali',
            'platform' => 'android',
            'logs' => [[
                'id' => 'api-fail',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 kitchen timeout',
            ], [
                'id' => 'ok-1',
                'level' => 'debug',
                'source' => 'api',
                'message' => 'GET /menu 200 ok',
            ]],
        ]);
        $sessions->heartbeat([
            'deviceId' => 'desktop-bar',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'cashierName' => 'Sara',
            'platform' => 'windows',
            'logs' => [[
                'id' => 'flutter-fail',
                'level' => 'error',
                'source' => 'flutter',
                'message' => 'null check on save order',
            ], [
                'id' => 'old-fail',
                'level' => 'error',
                'source' => 'api',
                'message' => 'POST /pay 502 old failure',
                'at' => now()->subDays(4)->toIso8601String(),
            ]],
        ]);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.failed'))
            ->assertOk()
            ->assertSee(__('kaman-support.failed_title'))
            ->assertSee(__('kaman-support.nav.failed'), false)
            ->assertSee('GCP Kaman', false)
            ->assertSee('Kaman Grill', false)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('null check on save order', false)
            ->assertSee('POST /pay 502 old failure', false)
            ->assertDontSee('GET /menu 200 ok', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.failed', ['restaurant' => 'GCP Kaman']))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertDontSee('null check on save order', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.failed', ['type' => 'flutter']))
            ->assertOk()
            ->assertSee('null check on save order', false)
            ->assertDontSee('GET /orders 500 kitchen timeout', false);

        $from = now()->timezone('Asia/Jerusalem')->subHour()->format('Y-m-d\TH:i');
        $to = now()->timezone('Asia/Jerusalem')->addHour()->format('Y-m-d\TH:i');
        $this->actingAs($this->yamen())
            ->get(route('kaman-support.failed', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('null check on save order', false)
            ->assertDontSee('POST /pay 502 old failure', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.failed.feed', ['type' => 'api', 'restaurant' => 'gcp']))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('count', 1)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertDontSee('null check on save order', false);

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.failed'))
            ->assertForbidden();
    }

    public function test_moaz_sees_only_1_0_live_logs(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $legacy = $sessions->heartbeat([
            'deviceId' => 'tablet-1-0',
            'restaurant' => 'old-branch',
            'restaurantName' => 'Old Branch',
            'cashierName' => 'Moaz Cashier',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'appVersion' => '1.0.6+12',
            'loggedIn' => true,
            'logs' => [[
                'id' => 'old-log',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 on 1.0',
            ]],
        ]);
        $current = $sessions->heartbeat([
            'deviceId' => 'tablet-1-3',
            'restaurant' => 'new-branch',
            'restaurantName' => 'New Branch',
            'cashierName' => 'Other Cashier',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'appVersion' => '1.3.15+38',
            'loggedIn' => true,
            'logs' => [[
                'id' => 'new-log',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 on 1.3',
            ]],
        ]);

        $moaz = User::query()->where('email', 'moaz@kaman.rest')->firstOrFail();

        $this->actingAs($moaz)
            ->get('/kaman-support/')
            ->assertRedirect(route('kaman-support.live'));

        $this->actingAs($moaz)
            ->get(route('kaman-support.live'))
            ->assertOk()
            ->assertSee('Old Branch', false)
            ->assertSee('GET /orders 500 on 1.0', false)
            ->assertDontSee('New Branch', false)
            ->assertDontSee('GET /orders 500 on 1.3', false)
            ->assertDontSee(__('kaman-support.nav.tickets'), false)
            ->assertDontSee('/kaman-support/failed', false);

        $this->actingAs($moaz)
            ->get(route('kaman-support.live.feed'))
            ->assertOk()
            ->assertSee('Old Branch', false)
            ->assertDontSee('New Branch', false);

        $this->actingAs($moaz)
            ->get(route('kaman-support.device', $legacy))
            ->assertOk()
            ->assertSee('GET /orders 500 on 1.0', false)
            ->assertDontSee(__('kaman-support.search_printers'), false);

        $this->actingAs($moaz)
            ->get(route('kaman-support.device', $current))
            ->assertNotFound();

        $this->actingAs($moaz)
            ->get(route('kaman-support.device.feed', $current))
            ->assertNotFound();

        $this->actingAs($moaz)->get(route('kaman-support.failed'))->assertForbidden();
        $this->actingAs($moaz)->get(route('kaman-support.show', $this->submitTicket()))->assertForbidden();
        $this->actingAs($moaz)
            ->post(route('kaman-support.device.commands', $legacy), ['name' => 'retry_failed_prints'])
            ->assertForbidden();
        $this->actingAs($moaz)->get(route('app-development.index'))->assertForbidden();
        $this->actingAs($moaz)->get(route('form.index'))->assertForbidden();

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live'))
            ->assertOk()
            ->assertSee('Old Branch', false)
            ->assertSee('New Branch', false);
    }
}
