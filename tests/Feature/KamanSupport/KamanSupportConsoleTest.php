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
            ->assertSee(__('kaman-support.board_title'), false)
            ->assertDontSee('kaman-sidebar', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.inbox'))
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
            ->assertSee('/downloads/kaman-support', false)
            ->assertSee(__('kaman-support.nav.download'), false)
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
            ->assertSee(__('kaman-support.view_bugs'), false)
            ->assertSee(__('kaman-support.source_flutter'), false)
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('/orders', false)
            ->assertSee('500', false)
            ->assertSee($jerusalemHour, false)
            ->assertSee('null check on bar tablet', false)
            ->assertSee(trans('kaman-support.failed_on_device', ['count' => 1]), false)
            ->assertDontSee('kitchen printer timeout', false)
            ->assertDontSee('tapped Save order', false)
            ->assertDontSee(__('kaman-support.view_debug'), false)
            ->assertDontSee('http-equiv="refresh"', false)
            ->assertDontSee('tap</option>', false)
            ->assertDontSee('print</option>', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', ['view' => 'flutter']))
            ->assertOk()
            ->assertSee('null check on bar tablet', false)
            ->assertDontSee('tapped Save order', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.live', [
                'view' => 'all',
                'from' => '2020-01-01T00:00',
                'to' => '2020-01-01T01:00',
                'source' => 'flutter',
                'level' => 'debug',
            ]))
            ->assertOk()
            ->assertSee('GET /orders 500 kitchen timeout', false)
            ->assertSee('GET /menu 200 ok', false)
            ->assertSee('tapped Save order', false)
            ->assertSee('null check on bar tablet', false);

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
            ->assertRedirect(route('kaman-support.index', ['open' => 'kaman']));

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device', [$tablet, 'view' => 'api']))
            ->assertRedirect(route('kaman-support.index', ['open' => 'kaman']));

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.device', [
                $tablet,
                'view' => 'api',
                'q' => '',
                'level' => '',
                'source' => '',
                'from' => '2020-01-01T00:00',
                'to' => '2020-01-01T01:00',
            ]))
            ->assertRedirect(route('kaman-support.index', ['open' => 'kaman']));

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
            ->assertDontSee('/kaman-support/failed', false)
            ->assertSee('/downloads/kaman-support', false)
            ->assertSee(__('kaman-support.nav.download'), false);

        $this->actingAs($moaz)
            ->get(route('kaman-support.live.feed'))
            ->assertOk()
            ->assertSee('Old Branch', false)
            ->assertDontSee('New Branch', false);

        $this->actingAs($moaz)
            ->get(route('kaman-support.device', $legacy))
            ->assertRedirect(route('kaman-support.live'));

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

    public function test_connections_user_sees_only_live_app_connections(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $legacy = $sessions->heartbeat([
            'deviceId' => 'tablet-1-0',
            'restaurant' => 'old-branch',
            'restaurantName' => 'Old Branch',
            'cashierName' => 'Legacy Cashier',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'appVersion' => '1.0.6+12',
            'loggedIn' => true,
            'printers' => [['name' => 'Secret Grill', 'ip' => '10.9.9.9', 'port' => 9100, 'role' => 'kitchen']],
            'logs' => [[
                'id' => 'old-log',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 on 1.0',
            ]],
        ]);
        $current = $sessions->heartbeat([
            'deviceId' => 'phone-3-0',
            'restaurant' => 'new-branch',
            'restaurantName' => 'New Branch',
            'cashierName' => 'Current Cashier',
            'platform' => 'android',
            'deviceKind' => 'phone',
            'appVersion' => '3.0.20+54',
            'loggedIn' => true,
            'logs' => [[
                'id' => 'new-log',
                'level' => 'error',
                'source' => 'api',
                'message' => 'GET /orders 500 on 3.0',
            ]],
        ]);

        $user = User::query()->where('email', 'connections@kaman.rest')->firstOrFail();
        $this->assertTrue($user->supportConnectionsOnly());
        $this->assertNull($user->posAppVersionPrefix());

        $this->actingAs($user)
            ->get('/kaman-support/')
            ->assertRedirect(route('kaman-support.live'));

        $this->actingAs($user)
            ->get(route('kaman-support.live', ['view' => 'all']))
            ->assertOk()
            ->assertSee(__('kaman-support.connections_title'), false)
            ->assertSee('Old Branch', false)
            ->assertSee('New Branch', false)
            ->assertSee('1.0.6+12', false)
            ->assertSee('3.0.20+54', false)
            ->assertDontSee('GET /orders 500 on 1.0', false)
            ->assertDontSee('GET /orders 500 on 3.0', false)
            ->assertDontSee('Secret Grill', false)
            ->assertDontSee(__('kaman-support.nav.tickets'), false)
            ->assertDontSee(__('kaman-support.view_all_logs'), false)
            ->assertDontSee('/kaman-support/failed', false)
            ->assertDontSee('/downloads/kaman-support', false);

        $this->actingAs($user)
            ->get(route('kaman-support.live.feed', ['view' => 'api']))
            ->assertOk()
            ->assertJsonPath('logs', [])
            ->assertJsonPath('live.0.printers', [])
            ->assertSee('Old Branch', false)
            ->assertSee('New Branch', false)
            ->assertDontSee('GET /orders 500', false)
            ->assertDontSee('Secret Grill', false)
            ->assertDontSee('10.9.9.9', false);

        $this->actingAs($user)
            ->get(route('kaman-support.device', $legacy))
            ->assertRedirect(route('kaman-support.live'));

        $this->actingAs($user)
            ->get(route('kaman-support.device.feed', $current))
            ->assertOk()
            ->assertJsonPath('logs', [])
            ->assertJsonPath('commands', [])
            ->assertJsonPath('device.printers', [])
            ->assertJsonPath('device.failedMessage', null)
            ->assertDontSee('GET /orders 500 on 3.0', false);

        $this->actingAs($user)->get(route('kaman-support.failed'))->assertForbidden();
        $this->actingAs($user)->get(route('kaman-support.show', $this->submitTicket()))->assertForbidden();
        $this->actingAs($user)
            ->post(route('kaman-support.device.commands', $legacy), ['name' => 'retry_failed_prints'])
            ->assertForbidden();
        $this->actingAs($user)->get(route('app-development.index'))->assertForbidden();
        $this->actingAs($user)->get(route('form.index'))->assertForbidden();
        $this->actingAs($user)->get(route('ai-chatbot.index'))->assertForbidden();
    }

    public function test_logs_workspace_groups_loaded_errors_without_dropping_them(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $message = 'API POST https://gcp.kaman.rest/api/cashier/print/01a0c484-7849-70a9-83ae-6958b1d5f8b7 -> 400 No kitchen items to print';
        $sessions->heartbeat([
            'deviceId' => 'cashier-1',
            'restaurant' => 'dochan',
            'restaurantName' => 'Dochan',
            'cashierName' => 'Cashier',
            'platform' => 'android',
            'appVersion' => '1.4.2',
            'logs' => [[
                'id' => 'print-1',
                'level' => 'error',
                'source' => 'print',
                'message' => $message,
            ]],
        ]);
        $sessions->heartbeat([
            'deviceId' => 'cashier-2',
            'restaurant' => 'dochan',
            'restaurantName' => 'Dochan',
            'cashierName' => 'Kitchen',
            'platform' => 'android',
            'appVersion' => '1.4.2',
            'logs' => [[
                'id' => 'print-2',
                'level' => 'error',
                'source' => 'print',
                'message' => 'API POST https://gcp.kaman.rest/api/cashier/print/9ef35062-c5fe-4a6d-9ad8-12f8b31d9034 -> 400 No kitchen items to print',
            ], [
                'id' => 'coupon-1',
                'level' => 'error',
                'source' => 'api',
                'message' => 'API POST https://gcp.kaman.rest/api/cashier/orders/9ef35062-c5fe-4a6d-9ad8-12f8b31d9034 -> 400 Invalid coupon',
            ]],
        ]);

        $response = $this->actingAs($this->yamen())->get(route('kaman-support.logs'));

        $response->assertOk();
        $response->assertSee('sup-inv__stage', false);
        $response->assertSee('No kitchen items to print', false);
        $response->assertSee('Invalid coupon', false);
        $response->assertSee('dir="ltr">×2</span>', false);
        $response->assertSee('dir="ltr">400</span>', false);
        $response->assertSee('01a0c484-7849-70a9-83ae-6958b1d5f8b7', false);
        $response->assertSee('9ef35062-c5fe-4a6d-9ad8-12f8b31d9034', false);
        $response->assertDontSee('class="sup-entry"', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs', ['lane' => 'server']))
            ->assertOk()
            ->assertSee(__('kaman-support.ws_inv_server_title'), false)
            ->assertSee(__('kaman-support.ws_inv_server_sub'), false)
            ->assertDontSee('No kitchen items to print', false)
            ->assertDontSee('Invalid coupon', false)
            ->assertDontSee('sup-inv__issue', false);
    }
}
