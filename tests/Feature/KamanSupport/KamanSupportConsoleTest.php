<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use App\Services\KamanSupport\SupportDeviceSessionService;
use App\Services\KamanSupport\SupportTicketService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_ticket_modal_plays_voice_and_screen_recordings(): void
    {
        Storage::fake('local');
        $ticket = $this->submitTicket([
            'clientTicketId' => 'KAM-REC001',
            'level' => 'danger_now',
        ]);
        app(SupportTicketService::class)->attachMedia(
            $ticket,
            UploadedFile::fake()->create('voice.wav', 12, 'audio/wav'),
            UploadedFile::fake()->create('screen.kscr', 16, 'application/octet-stream'),
        );

        $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.show', 'KAM-REC001'))
            ->assertOk()
            ->assertJsonPath('ticket.voice', route('kaman-support.tickets.audio', 'KAM-REC001'))
            ->assertJsonPath('ticket.screen', route('kaman-support.tickets.screen', 'KAM-REC001'))
            ->assertJsonPath('copy.voice', __('kaman-support.voice'));

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.inbox'))
            ->assertOk()
            ->assertSee('data-logo="kaman"', false)
            ->assertSee('ticket-view.js', false);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.tickets.audio', 'KAM-REC001'))
            ->assertOk();

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.tickets.screen', 'KAM-REC001'))
            ->assertOk();
    }

    public function test_restaurant_names_open_the_restaurant_modal_on_its_support_section(): void
    {
        \Illuminate\Support\Facades\Cache::put('kaman.rest.companies.v2', [[
            'id' => '01a0af60-0000-7000-8000-000000000001', 'name' => 'Kaman', 'subdomain' => 'kaman', 'url' => 'https://kaman.kaman.rest',
            'color' => '', 'lang' => 'ar', 'address' => '', 'logo' => '', 'hours' => '', 'cityId' => '',
        ]]);
        $ticket = $this->submitTicket();
        $this->submitTicket(['clientTicketId' => 'KAM-OTHER01', 'restaurant' => 'not-on-kaman-rest', 'restaurantName' => 'Other place']);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.inbox'))
            ->assertOk()
            ->assertSee('data-act="open-support" data-id="01a0af60-0000-7000-8000-000000000001"', false)
            ->assertSee('data-act="open-support" data-id="sup-not-on-kaman-rest"', false)
            ->assertSee('id="ag-portal"', false)
            ->assertSee('agents-workspace.js', false);

        $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.show', $ticket))
            ->assertOk()
            ->assertJsonPath('ticket.restaurantKey', '01a0af60-0000-7000-8000-000000000001');

        $support = app(\App\Services\KamanSupport\RestaurantSupport::class);
        $data = $support->byCompany($this->yamen());
        $this->assertSame($ticket->public_id, $data['01a0af60-0000-7000-8000-000000000001']['tickets'][0]['id']);
        $this->assertSame(1, $data['01a0af60-0000-7000-8000-000000000001']['openTickets']);
        $this->assertNotNull($data['01a0af60-0000-7000-8000-000000000001']['tickets'][0]['url']);
        $this->assertSame(['sup-not-on-kaman-rest'], array_column($support->extraRestaurants($this->yamen()), 'id'));
        $this->assertSame('kaman', $data['01a0af60-0000-7000-8000-000000000001']['slug']);
        $this->assertNotNull($support->boot($this->yamen())['card']);
    }

    public function test_restaurant_card_gives_the_boards_view_of_one_restaurant(): void
    {
        $ticket = $this->submitTicket();

        $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.restaurant.card', ['restaurant' => 'kaman']))
            ->assertOk()
            ->assertJsonPath('card.id', 'kaman')
            ->assertJsonPath('card.tickets.0.id', $ticket->public_id)
            ->assertJsonStructure(['card' => ['online', 'total', 'status', 'devices', 'tickets'], 'logs']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson(route('kaman-support.restaurant.card', ['restaurant' => 'kaman']))
            ->assertForbidden();
    }

    public function test_inbox_shows_the_pos_report_level(): void
    {
        $this->submitTicket([
            'level' => 'danger_now',
            'summary' => 'Danger now: the oven is off',
        ]);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.inbox'))
            ->assertOk()
            ->assertSee(__('kaman-support.level.danger_now'), false)
            ->assertSee('Danger now: the oven is off', false);

        $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.show', 'KAM-XSS001'))
            ->assertOk()
            ->assertJsonPath('ticket.levelLabel', __('kaman-support.level.danger_now'));
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

        // No ticket page: a ticket link opens the inbox with the ticket modal, which loads the ticket as JSON.
        $this->actingAs($this->yamen())
            ->get(route('kaman-support.show', $ticket))
            ->assertRedirect(route('kaman-support.inbox', ['ticket' => $ticket->public_id]));

        $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.show', $ticket))
            ->assertOk()
            ->assertJsonPath('ticket.id', $ticket->public_id)
            ->assertJsonPath('ticket.title', $ticket->summary);

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->get('/kaman-support/')
            ->assertForbidden();

        $this->actingAs(User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail())
            ->get('/kaman-support/')
            ->assertForbidden();
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

        \Illuminate\Support\Facades\Http::fake(['kaman.rest/*' => \Illuminate\Support\Facades\Http::response(['data' => ['companies' => []]])]);
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

    public function test_card_terminal_refusals_are_server_logs(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $sessions->heartbeat([
            'deviceId' => 'cashier-visa',
            'restaurant' => 'tazejitalian',
            'restaurantName' => 'Tazej',
            'cashierName' => 'kopa',
            'platform' => 'ios',
            'appVersion' => '1.3.76+100',
            'logs' => [[
                'id' => 'visa-user',
                'level' => 'error',
                'source' => 'server',
                'message' => 'POST /orders/01a19850/pay/visa -> 400 operation canceled by user',
            ], [
                'id' => 'visa-bank',
                'level' => 'error',
                'source' => 'api',
                'message' => 'POST /orders/01a19850/pay/visa -> 400 transaction refused.',
            ], [
                'id' => 'cash-canceled',
                'level' => 'error',
                'source' => 'api',
                'message' => 'POST /orders/01a19850/pay/cash -> 400 Order is canceled',
            ]],
        ]);

        $device = $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs', ['restaurant' => 'tazejitalian']));
        $device->assertOk();
        $device->assertSee('Order is canceled', false);
        $device->assertDontSee('operation canceled by user', false);
        $device->assertDontSee('transaction refused', false);

        $server = $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs', ['lane' => 'server', 'restaurant' => 'tazejitalian']));
        $server->assertOk();
        $server->assertSee('operation canceled by user', false);
        $server->assertSee('transaction refused', false);
        $server->assertDontSee('Order is canceled', false);
        $server->assertDontSee(__('kaman-support.ws_inv_server_title'), false);
    }

    public function test_the_app_decides_the_product_not_the_version(): void
    {
        $sessions = app(SupportDeviceSessionService::class);
        $device = fn (string $id, array $extra) => $sessions->heartbeat([
            'deviceId' => $id, 'restaurant' => $id, 'platform' => 'android', ...$extra,
        ]);
        $products = fn (string $id) => $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.restaurant.card', $id))
            ->assertOk()
            ->json('card.products');

        $device('lite-on-1-0', ['appVersion' => '1.0.7+3', 'product' => 'lite']);
        $device('mall-on-1-0', ['appVersion' => '1.0.7+3', 'product' => 'MALL']);
        $device('old-1-0', ['appVersion' => '1.0.7+3']);
        $device('old-1-3', ['appVersion' => '1.3.15+38']);
        $device('odd', ['appVersion' => '1.0.7+3', 'product' => 'something-else']);

        $this->assertSame(['lite'], $products('lite-on-1-0'));
        $this->assertSame(['mall'], $products('mall-on-1-0'));
        $this->assertSame([], $products('old-1-0'));
        $this->assertSame(['lite'], $products('old-1-3'));
        $this->assertSame([], $products('odd'));

        // A later heartbeat that leaves the product out keeps it.
        $kept = $device('lite-on-1-0', ['appVersion' => '1.0.7+3']);
        $this->assertSame('lite', $kept->productKey());

        // A smaller heartbeat that leaves the version out keeps it too.
        $this->assertSame('1.0.7+3', $device('mall-on-1-0', [])->app_version);
        $this->assertSame('2.0.0', $device('mall-on-1-0', ['appVersion' => '2.0.0'])->app_version);
    }

    public function test_logs_label_the_product_the_app_reported(): void
    {
        \Illuminate\Support\Facades\Http::fake(['kaman.rest/*' => \Illuminate\Support\Facades\Http::response(['data' => ['companies' => []]])]);
        app(SupportDeviceSessionService::class)->heartbeat([
            'deviceId' => 'tablet-1', 'restaurant' => 'dochan', 'platform' => 'android',
            'appVersion' => '1.0.7+3', 'product' => 'lite',
            'logs' => [['id' => 'log-1', 'level' => 'error', 'source' => 'api', 'message' => 'Invalid coupon']],
        ]);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs'))
            ->assertOk()
            ->assertSee('dir="ltr">'.__('kaman-support.ws_lite').'</span>', false)
            ->assertDontSee('dir="ltr">'.__('kaman-support.ws_cashier_mall').'</span>', false);
    }

    public function test_logs_restaurant_filter_lists_real_restaurants_only(): void
    {
        \Illuminate\Support\Facades\Cache::put('kaman.rest.companies.v2', [[
            'id' => 'company-1', 'name' => 'Master Burger', 'subdomain' => 'MasterBurger', 'url' => '',
            'color' => '', 'lang' => 'ar', 'address' => '', 'logo' => 'https://admin.kaman.rest/storage/logos/master.png', 'hours' => '', 'cityId' => '',
        ], [
            'id' => 'company-2', 'name' => 'Asado', 'subdomain' => 'asado', 'url' => '',
            'color' => '', 'lang' => 'ar', 'address' => '', 'logo' => '', 'hours' => '', 'cityId' => '',
        ]]);
        $sessions = app(SupportDeviceSessionService::class);
        foreach (['masterburger', '_pending', 'ghost'] as $restaurant) {
            $sessions->heartbeat(['deviceId' => 'tablet-'.$restaurant, 'restaurant' => $restaurant, 'platform' => 'android', 'appVersion' => '1.4.2']);
        }

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs'))
            ->assertOk()
            ->assertSee('name="restaurants[]" value="asado"', false)
            ->assertSee('name="restaurants[]" value="masterburger"', false)
            ->assertSee('Master Burger')
            ->assertSee('https://admin.kaman.rest/storage/logos/master.png', false)
            ->assertDontSee('value="MasterBurger"', false)
            ->assertDontSee('value="_pending"', false)
            ->assertDontSee('value="ghost"', false);

        // A restaurant already picked stays listed, ticked, so it can be cleared.
        $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs', ['restaurants' => ['ghost']]))
            ->assertOk()
            ->assertSee('name="restaurants[]" value="ghost" checked', false)
            ->assertDontSee('value="_pending"', false);
    }

    public function test_logs_restaurant_filter_falls_back_when_kaman_rest_is_unreachable(): void
    {
        \Illuminate\Support\Facades\Cache::forget('kaman.rest.companies.v2');
        \Illuminate\Support\Facades\Http::fake(['kaman.rest/*' => \Illuminate\Support\Facades\Http::response('', 500)]);
        app(SupportDeviceSessionService::class)->heartbeat(['deviceId' => 'tablet-1', 'restaurant' => 'dochan', 'platform' => 'android', 'appVersion' => '1.4.2']);

        $this->actingAs($this->yamen())
            ->get(route('kaman-support.logs'))
            ->assertOk()
            ->assertSee('name="restaurants[]" value="dochan"', false);
    }

    public function test_recent_logs_show_failures_of_the_last_48_hours_and_no_successes(): void
    {
        $device = SupportDevice::query()->forceCreate(['restaurant' => 'kaman', 'device_id' => 'till-1', 'last_seen_at' => now()]);
        $log = fn (string $id, string $level, string $message, int $hoursAgo) => $device->logs()->forceCreate([
            'client_id' => $id, 'level' => $level, 'source' => 'api', 'message' => $message, 'logged_at' => now()->subHours($hoursAgo),
        ]);
        $log('a', 'error', 'printer jammed', 1);
        $log('b', 'info', 'GET /orders -> 500 kitchen down', 2);
        $log('c', 'warn', 'POST /pay 402 refused', 3);
        $log('d', 'info', 'GET /menu -> 200 ok', 1);
        $log('e', 'info', 'tapped Save order', 1);
        $log('f', 'error', 'an old error', 50);

        $messages = $this->actingAs($this->yamen())
            ->getJson(route('kaman-support.live.feed', ['restaurant' => 'kaman', 'view' => 'failed', 'hours' => 48, 'limit' => 200]))
            ->assertOk()
            ->json('logs.*.message');

        $this->assertEqualsCanonicalizing(['printer jammed', 'GET /orders -> 500 kitchen down', 'POST /pay 402 refused'], $messages);
    }

    public function test_a_restaurant_card_marks_devices_not_seen_for_3_days_as_old(): void
    {
        foreach (['fresh' => now()->subDays(2), 'stale' => now()->subDays(4), 'never' => null] as $id => $seen) {
            SupportDevice::query()->forceCreate(['restaurant' => 'kaman', 'device_id' => $id, 'last_seen_at' => $seen]);
        }

        $devices = collect($this->actingAs($this->yamen())
            ->getJson(route('kaman-support.restaurant.card', ['restaurant' => 'kaman']))
            ->assertOk()
            ->json('card.devices'))->keyBy('deviceId');

        $this->assertFalse($devices['fresh']['old']);
        $this->assertTrue($devices['stale']['old']);
        $this->assertTrue($devices['never']['old']);
    }
}
