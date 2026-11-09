<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Enums\KamanSupport\SupportTicketStatus;
use App\Enums\Project;
use App\Enums\KamanSupport\SupportCommandStatus;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceCommand;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KamanSupportApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'testing-kaman-support-key';

    /**
     * @return array<string, mixed>
     */
    private function sampleTicket(array $overrides = []): array
    {
        return array_merge([
            'clientTicketId' => 'KAM-A3F2B1',
            'createdAt' => '2026-09-19T01:00:00.000Z',
            'summary' => 'طلب 45 لا يطبع على جريل حسب أنواع المطبخ في الـ CRM',
            'details' => 'full diagnostic dump / AI summary',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman',
            'cashierName' => 'Ali',
            'deviceId' => 'pos-device-1',
            'locale' => 'ar',
            'conversation' => ['user: الطابعه الخاصه بالجريل ما طبعت', 'ticket: KAM-A3F2B1'],
            'diagnosis' => [
                'cause' => 'noMatchingKitchenType',
                'printer' => 'جريل',
                'ip' => '10.0.0.3',
                'reachable' => true,
                'order' => 45,
            ],
            'printers' => [[
                'name' => 'جريل',
                'role' => 'kitchen',
                'station' => 'جريل',
                'ip' => '10.0.0.3:9100',
                'issue' => 'none',
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postTicket(array $payload, ?string $key = self::KEY)
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($key !== null) {
            $headers['X-Kaman-Support-Key'] = $key;
        }

        return $this->postJson('/api/kaman-support/v1/tickets', $payload, $headers);
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/kaman-support/v1/health')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'service' => 'kaman-support',
            ]);
    }

    public function test_pos_can_create_a_ticket_with_arabic_text(): void
    {
        $response = $this->postTicket($this->sampleTicket())
            ->assertCreated()
            ->assertJsonPath('id', 'KAM-A3F2B1')
            ->assertJsonPath('clientTicketId', 'KAM-A3F2B1')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('diagnosis.printer', 'جريل');

        $response->assertSee('طلب 45', false);

        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame('طلب 45 لا يطبع على جريل حسب أنواع المطبخ في الـ CRM', SupportTicket::query()->first()?->summary);

        $device = SupportDevice::query()->first();
        $this->assertNotNull($device);
        $this->assertSame('kaman', $device->restaurant);
        $this->assertSame('pos-device-1', $device->device_id);
        $this->assertSame('KAM-A3F2B1', $device->last_ticket_id);
    }

    public function test_same_client_ticket_id_is_idempotent_per_restaurant(): void
    {
        $this->postTicket($this->sampleTicket())->assertCreated();
        $this->postTicket($this->sampleTicket(['summary' => 'should not replace']))
            ->assertOk()
            ->assertJsonPath('id', 'KAM-A3F2B1')
            ->assertJsonPath('summary', 'طلب 45 لا يطبع على جريل حسب أنواع المطبخ في الـ CRM');

        $this->assertSame(1, SupportTicket::query()->count());
    }

    public function test_missing_client_ticket_id_generates_kam_id(): void
    {
        $id = $this->postTicket($this->sampleTicket([
            'clientTicketId' => null,
            'summary' => 'Printer offline',
        ]))
            ->assertCreated()
            ->json('id');

        $this->assertMatchesRegularExpression('/^KAM-[A-Z0-9]{6}$/', $id);
    }

    public function test_pos_report_level_is_stored_with_the_restaurant(): void
    {
        $this->postTicket($this->sampleTicket([
            'level' => 'danger_now',
            'summary' => 'Danger now: the oven is off',
        ]))
            ->assertCreated()
            ->assertJsonPath('restaurant', 'kaman')
            ->assertJsonPath('extra.level', 'danger_now');

        $this->assertSame('danger_now', SupportTicket::query()->first()?->reportLevel());

        $this->postTicket($this->sampleTicket([
            'clientTicketId' => 'KAM-BADLVL',
            'level' => 'panic',
        ]))->assertUnprocessable();
    }

    public function test_pos_uploads_voice_and_screen_without_exposing_paths(): void
    {
        Storage::fake('local');

        $this->postTicket($this->sampleTicket([
            'clientTicketId' => 'KAM-MEDIA1',
            'level' => 'issue',
        ]))->assertCreated();

        $response = $this->post(
            '/api/kaman-support/v1/tickets/KAM-MEDIA1/media',
            [
                'audio' => UploadedFile::fake()->create('voice.m4a', 20, 'audio/mp4'),
                'screen' => UploadedFile::fake()->create('screen.kscr', 30, 'application/octet-stream'),
            ],
            [
                'Accept' => 'application/json',
                'X-Kaman-Support-Key' => self::KEY,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('hasVoice', true)
            ->assertJsonPath('hasScreen', true)
            ->assertJsonPath('extra.level', 'issue');

        $this->assertArrayNotHasKey('audioPath', $response->json('extra'));
        $this->assertArrayNotHasKey('screenPath', $response->json('extra'));

        $ticket = SupportTicket::query()->where('public_id', 'KAM-MEDIA1')->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($ticket->mediaPath('audioPath')));
        $this->assertTrue(Storage::disk('local')->exists($ticket->mediaPath('screenPath')));
        $this->assertSame('issue', $ticket->reportLevel());

        $this->post(
            '/api/kaman-support/v1/tickets/KAM-MEDIA1/media',
            ['audio' => UploadedFile::fake()->create('notes.exe', 10, 'application/octet-stream')],
            ['Accept' => 'application/json', 'X-Kaman-Support-Key' => self::KEY],
        )->assertUnprocessable();
    }

    public function test_unknown_fields_are_kept_in_extra(): void
    {
        $this->postTicket($this->sampleTicket([
            'appVersion' => '1.4.0',
            'futureFlag' => true,
        ]))->assertCreated();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame('1.4.0', $ticket->extra['appVersion'] ?? null);
        $this->assertTrue($ticket->extra['futureFlag'] ?? false);
    }

    public function test_summary_is_required_and_key_is_required(): void
    {
        $this->postTicket(['restaurant' => 'kaman'])->assertUnprocessable();
        $this->postTicket($this->sampleTicket(), key: null)->assertUnauthorized();
        $this->postTicket($this->sampleTicket(), key: 'wrong')->assertUnauthorized();
    }

    public function test_oversized_body_is_rejected(): void
    {
        config(['kaman-support.max_body_bytes' => 64]);

        $this->postTicket($this->sampleTicket([
            'details' => str_repeat('x', 200),
        ]))->assertStatus(413);
    }

    public function test_worker_list_and_detail_require_yamen(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->postTicket($this->sampleTicket())->assertCreated();

        $this->getJson('/api/kaman-support/v1/tickets')->assertUnauthorized();

        $burhan = User::query()->where('email', 'burhan@kaman.rest')->firstOrFail();
        $this->actingAs($burhan)
            ->getJson('/api/kaman-support/v1/tickets')
            ->assertForbidden();

        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $this->actingAs($yamen)
            ->getJson('/api/kaman-support/v1/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'KAM-A3F2B1')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($yamen)
            ->getJson('/api/kaman-support/v1/tickets/KAM-A3F2B1')
            ->assertOk()
            ->assertJsonPath('diagnosis.cause', 'noMatchingKitchenType')
            ->assertJsonPath('events.0.type', 'created');
    }

    public function test_worker_can_patch_status_and_add_an_event(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->postTicket($this->sampleTicket())->assertCreated();
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->patchJson('/api/kaman-support/v1/tickets/KAM-A3F2B1', [
                'status' => SupportTicketStatus::InProgress->value,
                'assignee' => 'Yamen',
                'note' => 'Looking at grill routing',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('assignee', 'Yamen');

        $this->actingAs($yamen)
            ->postJson('/api/kaman-support/v1/tickets/KAM-A3F2B1/events', [
                'type' => 'note',
                'message' => 'Asked cashier to reprint',
            ])
            ->assertCreated();

        $types = SupportTicket::query()->firstOrFail()->events()->pluck('type')->map->value->all();
        $this->assertContains('status_changed', $types);
        $this->assertContains('note', $types);
    }

    public function test_pos_heartbeat_and_logs_are_stored(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-live-1',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'cashierName' => 'Ali',
            'cashierEmail' => 'ali@kaman.com',
            'role' => 'cashier',
            'platform' => 'android',
            'deviceKind' => 'tablet',
            'appVersion' => '1.3.15+38',
            'hostname' => 'FRONT-POS',
            'locale' => 'ar',
            'loggedIn' => true,
            'appState' => 'open',
            'logs' => [[
                'id' => 'log-1',
                'level' => 'info',
                'source' => 'auth',
                'message' => 'signed in',
                'at' => now()->toIso8601String(),
            ]],
            'printers' => [[
                'ip' => '10.0.0.3',
                'port' => 9100,
                'saved' => true,
                'receipt' => true,
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deviceId', 'pos-live-1')
            ->assertJsonPath('online', true)
            ->assertJsonPath('watch', false)
            ->assertJsonPath('pollMs', 15000);

        $device = SupportDevice::query()->first();
        $this->assertNotNull($device);
        $this->assertSame('Kaman Grill', $device->restaurant_name);
        $this->assertSame('Ali', $device->cashier_name);
        $this->assertSame('android', $device->platform);
        $this->assertSame('tablet', $device->deviceKind());
        $this->assertTrue($device->isTablet());
        $this->assertTrue($device->isLive());
        $this->assertSame(1, $device->logs()->count());
        $this->assertSame('signed in', $device->logs()->first()?->message);
        $this->assertSame('10.0.0.3', $device->extra['printers'][0]['ip'] ?? null);

        $this->postJson('/api/kaman-support/v1/devices/logs', [
            'deviceId' => 'pos-live-1',
            'restaurant' => 'kaman',
            'logs' => [[
                'id' => 'log-1',
                'level' => 'info',
                'source' => 'auth',
                'message' => 'signed in again',
            ], [
                'id' => 'log-2',
                'level' => 'error',
                'source' => 'print',
                'message' => 'printer 10.0.0.3 timeout',
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $this->assertSame(2, SupportDevice::query()->first()?->logs()->count());
        $this->assertSame('signed in', SupportDevice::query()->first()?->logs()->reorder()->orderBy('id')->value('message'));
    }

    public function test_heartbeat_keeps_one_row_for_the_same_local_printer(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-dup-1',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'printers' => [
                [
                    'ip' => '10.0.0.8',
                    'port' => 9100,
                    'saved' => true,
                    'receipt' => true,
                ],
                [
                    'ip' => '10.0.0.8',
                    'port' => 9100,
                    'mac' => 'aa:bb:cc:dd:ee:ff',
                    'saved' => true,
                    'kitchen' => true,
                    'stations' => ['Grill'],
                ],
                [
                    'ip' => '10.0.0.8',
                    'port' => 9100,
                    'saved' => false,
                ],
            ],
            'discovered' => [[
                'ip' => '10.0.0.8',
                'port' => 9100,
                'mac' => 'aa:bb:cc:dd:ee:ff',
            ], [
                'ip' => '10.0.0.9',
                'port' => 9100,
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $device = SupportDevice::query()->first();
        $this->assertNotNull($device);
        $this->assertCount(1, $device->extra['printers'] ?? []);
        $this->assertSame('10.0.0.8', $device->extra['printers'][0]['ip'] ?? null);
        $this->assertTrue((bool) ($device->extra['printers'][0]['receipt'] ?? false));
        $this->assertTrue((bool) ($device->extra['printers'][0]['kitchen'] ?? false));
        $this->assertSame(['Grill'], $device->extra['printers'][0]['stations'] ?? null);
        $this->assertCount(1, $device->extra['discovered'] ?? []);
        $this->assertSame('10.0.0.9', $device->extra['discovered'][0]['ip'] ?? null);
    }

    public function test_heartbeat_hides_discovered_printers_that_match_a_saved_address(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-dup-2',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'printers' => [[
                'ip' => '10.0.0.8:9100',
                'saved' => true,
                'receipt' => true,
            ]],
            'discovered' => [[
                'address' => '10.0.0.8',
                'port' => 9100,
            ], [
                'ip' => '10.0.0.8',
                'mac' => 'AA-BB-CC-DD-EE-FF',
            ], [
                'ip' => '10.0.0.11',
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $device = SupportDevice::query()->first();
        $this->assertNotNull($device);
        $this->assertCount(1, $device->extra['printers'] ?? []);
        $this->assertSame('10.0.0.8', $device->extra['printers'][0]['ip'] ?? null);
        $this->assertCount(1, $device->extra['discovered'] ?? []);
        $this->assertSame('10.0.0.11', $device->extra['discovered'][0]['ip'] ?? null);
    }

    public function test_heartbeat_stores_the_tablet_lan_map_beside_printers(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-lan-1',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'printers' => [[
                'ip' => '10.0.0.8',
                'port' => 9100,
                'saved' => true,
                'receipt' => true,
            ]],
            'lanScan' => ['running' => false, 'done' => 3, 'total' => 3, 'phase' => ''],
            'lan' => [
                'subnet' => '10.0.0.0/24',
                'gateway' => '10.0.0.1',
                'method' => 'ping+arp',
                'nodes' => [
                    ['id' => '10.0.0.8', 'ip' => '10.0.0.8', 'role' => 'tablet', 'online' => true],
                    ['id' => '10.0.0.1', 'ip' => '10.0.0.1', 'role' => 'gateway', 'online' => true],
                    ['id' => '10.0.0.9', 'ip' => '10.0.0.9', 'role' => 'printer', 'online' => true, 'ports' => [['port' => 9100, 'name' => 'thermal print']]],
                ],
                'links' => [
                    ['from' => '10.0.0.8', 'to' => '10.0.0.1', 'kind' => 'uplink'],
                    ['from' => '10.0.0.1', 'to' => '10.0.0.9', 'kind' => 'lan'],
                ],
            ],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $device = SupportDevice::query()->where('device_id', 'pos-lan-1')->first();
        $this->assertNotNull($device);
        $this->assertSame('10.0.0.0/24', $device->extra['lan']['subnet'] ?? null);
        $this->assertCount(3, $device->extra['lan']['nodes'] ?? []);
        $this->assertSame('uplink', $device->extra['lan']['links'][0]['kind'] ?? null);
        $this->assertSame('10.0.0.8', $device->extra['printers'][0]['ip'] ?? null);

        $state = app(\App\Services\KamanSupport\SupportDeviceSessionService::class)->deviceState($device);
        $this->assertSame('10.0.0.1', $state['lan']['gateway'] ?? null);
        $this->assertCount(2, $state['lan']['links'] ?? []);
        $this->assertFalse($state['lanScan']['running'] ?? true);
    }

    public function test_heartbeat_stores_the_pos_settings_page_for_the_device_modal(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-settings-1',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'settings' => [
                'printKitchenCancel' => true,
                'localZReport' => false,
                'printReceiptOnReceive' => true,
                'allowKitchenReprint' => true,
                'kitchenReprintPassword' => '2468',
                'autoPrintOnThisTablet' => true,
                'builtInKeyboard' => false,
                'pizzaInterface' => true,
                'pizzaMealIds' => ['12', '12', ' 9 '],
                'locale' => 'en',
                'openaiApiKey' => 'sk-secret',
            ],
            'pizzaMeals' => [
                ['id' => '12', 'name' => 'XL Classic'],
                ['id' => '', 'name' => 'skip'],
                ['id' => '9', 'name' => 'Margherita'],
            ],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $device = SupportDevice::query()->where('device_id', 'pos-settings-1')->first();
        $this->assertNotNull($device);
        $this->assertTrue($device->extra['settings']['printKitchenCancel'] ?? false);
        $this->assertFalse($device->extra['settings']['builtInKeyboard'] ?? true);
        $this->assertSame('2468', $device->extra['settings']['kitchenReprintPassword'] ?? null);
        $this->assertSame(['12', '9'], $device->extra['settings']['pizzaMealIds'] ?? null);
        $this->assertArrayNotHasKey('locale', $device->extra['settings']);
        $this->assertArrayNotHasKey('openaiApiKey', $device->extra['settings']);
        $this->assertSame('XL Classic', $device->extra['pizzaMeals'][0]['name'] ?? null);

        $sessions = app(\App\Services\KamanSupport\SupportDeviceSessionService::class);
        $state = $sessions->deviceState($device);
        $this->assertTrue($state['settings']['pizzaInterface'] ?? false);
        $this->assertCount(2, $state['pizzaMeals'] ?? []);
        $connection = $sessions->connectionState($device);
        $this->assertNull($connection['settings']);
        $this->assertSame([], $connection['pizzaMeals']);
    }

    public function test_worker_can_queue_a_printer_job_for_the_next_heartbeat(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->postTicket($this->sampleTicket())->assertCreated();
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->postJson('/api/kaman-support/v1/tickets/KAM-A3F2B1/commands', [
                'name' => 'ping_printer',
                'args' => ['printer' => 'جريل'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'ping_printer');

        $queued = SupportDeviceCommand::query()->first();
        $this->assertNotNull($queued);
        $this->assertSame(SupportCommandStatus::Queued, $queued->status);

        $heartbeat = $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-device-1',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'loggedIn' => true,
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $heartbeat->assertJsonPath('commands.0.name', 'ping_printer');
        $heartbeat->assertJsonPath('commands.0.args.printer', 'جريل');
        $commandId = $heartbeat->json('commands.0.id');
        $this->assertNotEmpty($commandId);

        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-device-1',
            'restaurant' => 'kaman',
            'appState' => 'open',
            'commandResults' => [[
                'id' => $commandId,
                'ok' => true,
                'result' => ['reachable' => true, 'summary' => 'جريل 10.0.0.3'],
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk()->assertJsonPath('commands', []);

        $queued->refresh();
        $this->assertSame(SupportCommandStatus::Done, $queued->status);
        $this->assertTrue($queued->result['reachable'] ?? false);

        $types = SupportTicket::query()->firstOrFail()->events()->pluck('type')->map->value->all();
        $this->assertContains('command_result', $types);
    }

    public function test_ticket_command_without_a_device_is_rejected(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->postTicket($this->sampleTicket([
            'deviceId' => '',
            'restaurant' => '',
        ]))->assertCreated();
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->postJson('/api/kaman-support/v1/tickets/KAM-A3F2B1/commands', [
                'name' => 'scan_lan',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'device_offline');
    }

    public function test_cors_preflight_allows_flutter_localhost(): void
    {
        $this->call('OPTIONS', '/api/kaman-support/v1/tickets', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:12345',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:12345');
    }

    public function test_yamen_has_explicit_support_grant_and_other_admins_do_not(): void
    {
        $this->seed(WorkspaceUserSeeder::class);

        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $burhan = User::query()->where('email', 'burhan@kaman.rest')->firstOrFail();

        $this->assertTrue($yamen->canAccessProject(Project::KamanSupport));
        $this->assertFalse($burhan->canAccessProject(Project::KamanSupport));
        $this->assertTrue($burhan->is_admin);
    }

    public function test_failure_logs_last_14_days_and_routine_logs_last_a_day(): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-prune-1',
            'restaurant' => 'kaman',
            'logs' => [[
                'id' => 'fresh-info',
                'level' => 'info',
                'source' => 'auth',
                'message' => 'signed in',
                'at' => now()->toIso8601String(),
            ]],
        ], [
            'X-Kaman-Support-Key' => self::KEY,
        ])->assertOk();

        $device = SupportDevice::query()->where('device_id', 'pos-prune-1')->firstOrFail();
        $rows = [
            ['info-old', 'info', 'tapped pay', now()->subDays(2)],
            ['error-old', 'error', 'GET /orders -> 500', now()->subDays(15)],
            ['error-kept', 'error', 'printer timeout', now()->subDays(10)],
            ['warn-kept', 'warn', 'link down', now()->subDays(3)],
        ];
        foreach ($rows as [$clientId, $level, $message, $at]) {
            SupportDeviceLog::query()->create([
                'support_device_id' => $device->id,
                'client_id' => $clientId,
                'level' => $level,
                'source' => 'app',
                'message' => $message,
                'logged_at' => $at,
            ]);
        }
        $device->forceFill(['log_count' => 5])->save();

        $this->artisan('kaman-support:prune-logs')->assertSuccessful();

        $messages = SupportDeviceLog::query()
            ->where('support_device_id', $device->id)
            ->orderBy('id')
            ->pluck('message')
            ->all();

        $this->assertSame(['signed in', 'printer timeout', 'link down'], $messages);
        $this->assertSame(3, $device->fresh()->log_count);
    }

    public function test_heartbeat_without_logs_leaves_expired_rows_for_the_daily_prune(): void
    {
        $headers = ['X-Kaman-Support-Key' => self::KEY];
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-prune-2',
            'restaurant' => 'kaman',
            'logs' => [[
                'id' => 'fresh-info',
                'level' => 'info',
                'source' => 'auth',
                'message' => 'signed in',
                'at' => now()->toIso8601String(),
            ]],
        ], $headers)->assertOk();

        $device = SupportDevice::query()->where('device_id', 'pos-prune-2')->firstOrFail();
        SupportDeviceLog::query()->create([
            'support_device_id' => $device->id,
            'client_id' => 'info-old',
            'level' => 'info',
            'source' => 'app',
            'message' => 'tapped pay',
            'logged_at' => now()->subDays(2),
        ]);

        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-prune-2',
            'restaurant' => 'kaman',
            'appState' => 'open',
        ], $headers)->assertOk();

        $this->assertNotNull(
            SupportDeviceLog::query()->where('support_device_id', $device->id)->where('client_id', 'info-old')->first()
        );
    }
}
