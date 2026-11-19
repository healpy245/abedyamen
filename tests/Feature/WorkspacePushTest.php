<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkspacePushDevice;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Restaurant tickets and POS errors ring the Kaman Workspace phones through
 * Firebase Cloud Messaging, as each phone's settings allow.
 */
class WorkspacePushTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'testing-kaman-support-key';

    private const TOKEN = 'fcm-token-for-yamen-phone-0123456789';

    private string $credentials;

    private bool $fcmGone = false;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway key made only for these tests; it signs nothing real.
        $pem = (string) file_get_contents(base_path('tests/Fixtures/fcm-test-key.pem'));
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentials, json_encode([
            'type' => 'service_account',
            'project_id' => 'kaman-test',
            'client_email' => 'push@kaman-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['services.fcm.credentials' => $this->credentials]);
        Cache::flush();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn () => $this->fcmGone
                ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/kaman-test/messages/1']),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    private function yamen(): User
    {
        return User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function register(array $extra = [], string $token = self::TOKEN): void
    {
        $this->actingAs($this->yamen())
            ->postJson(route('mobile.push-devices.store'), $extra + ['token' => $token, 'platform' => 'android', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('serverReady', true)
            ->assertJsonPath('canTickets', true)
            ->assertJsonPath('canFailures', true);
    }

    private function submitTicket(string $id = 'KAM-PUSH01'): void
    {
        $this->postJson('/api/kaman-support/v1/tickets', [
            'clientTicketId' => $id,
            'summary' => 'Grill printer does not print',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'cashierName' => 'Ali',
            'deviceId' => 'pos-1',
        ], ['X-Kaman-Support-Key' => self::KEY])->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $logs
     */
    private function heartbeat(array $logs): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => 'pos-1',
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'appState' => 'open',
            'logs' => $logs,
        ], ['X-Kaman-Support-Key' => self::KEY])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pushes(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'))
            ->map(fn (Request $request) => $request->data()['message'])
            ->values()
            ->all();
    }

    public function test_a_new_restaurant_ticket_pushes_to_the_registered_phone(): void
    {
        $this->register();
        $this->submitTicket();

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame(self::TOKEN, $pushes[0]['token']);
        $this->assertSame('New support ticket · Kaman Grill', $pushes[0]['notification']['title']);
        $this->assertSame('Grill printer does not print', $pushes[0]['notification']['body']);
        $this->assertSame(['type' => 'ticket', 'ticket' => 'KAM-PUSH01', 'restaurant' => 'kaman'], $pushes[0]['data']);
        $this->assertSame('support_tickets', $pushes[0]['android']['notification']['channel_id']);
        $this->assertSame('HIGH', $pushes[0]['android']['priority']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'projects/kaman-test/messages:send')
            && $request->hasHeader('Authorization', 'Bearer google-access'));
        $this->assertNotNull(WorkspacePushDevice::query()->sole()->last_sent_at);
    }

    public function test_a_phone_that_turned_tickets_off_gets_none(): void
    {
        $this->register(['notifyTickets' => false]);
        $this->submitTicket();

        $this->assertSame([], $this->pushes());
    }

    public function test_pos_errors_push_once_per_restaurant_in_the_cooldown(): void
    {
        $this->register();
        $this->heartbeat([
            ['id' => 'e1', 'level' => 'error', 'source' => 'api', 'message' => 'POST /orders failed 500'],
            ['id' => 'e2', 'level' => 'error', 'source' => 'api', 'message' => 'POST /orders failed 502'],
            ['id' => 'i1', 'level' => 'info', 'source' => 'app', 'message' => 'opened'],
        ]);
        $this->heartbeat([
            ['id' => 'e3', 'level' => 'error', 'source' => 'api', 'message' => 'POST /orders failed 503'],
        ]);

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('Errors at Kaman Grill', $pushes[0]['notification']['title']);
        $this->assertSame('2 new errors: POST /orders failed 500', $pushes[0]['notification']['body']);
        $this->assertSame('failures', $pushes[0]['data']['type']);
        $this->assertSame('support_failures', $pushes[0]['android']['notification']['channel_id']);
    }

    public function test_info_logs_and_phones_with_errors_off_get_no_push(): void
    {
        $this->register(['notifyFailures' => false]);
        $this->heartbeat([['id' => 'e1', 'level' => 'error', 'source' => 'api', 'message' => 'failed']]);
        Cache::flush();
        $this->register(['notifyFailures' => true]);
        $this->heartbeat([['id' => 'i1', 'level' => 'info', 'source' => 'app', 'message' => 'opened']]);

        $this->assertSame([], $this->pushes());
    }

    public function test_the_push_speaks_the_phone_language(): void
    {
        $this->register(['locale' => 'ar']);
        $this->submitTicket();

        $this->assertSame('تذكرة دعم جديدة · Kaman Grill', $this->pushes()[0]['notification']['title']);
    }

    public function test_a_token_firebase_no_longer_knows_is_dropped(): void
    {
        $this->fcmGone = true;
        $this->register();
        $this->submitTicket();

        $this->assertSame(0, WorkspacePushDevice::query()->count());
    }

    public function test_settings_are_saved_and_sign_out_removes_the_phone(): void
    {
        $this->register(['notifyTickets' => false, 'notifyFailures' => true]);
        $phone = WorkspacePushDevice::query()->sole();
        $this->assertFalse($phone->notify_tickets);
        $this->assertTrue($phone->notify_failures);

        $this->register(['notifyTickets' => true]);
        $this->assertTrue(WorkspacePushDevice::query()->sole()->notify_tickets);

        $this->actingAs($this->yamen())
            ->postJson(route('mobile.push-devices.destroy'), ['token' => self::TOKEN])
            ->assertOk();
        $this->assertSame(0, WorkspacePushDevice::query()->count());
    }

    public function test_someone_without_support_hears_nothing(): void
    {
        $burhan = User::query()->where('email', 'burhan@kaman.rest')->firstOrFail();
        $this->actingAs($burhan)
            ->postJson(route('mobile.push-devices.store'), ['token' => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('canTickets', false);

        $this->submitTicket();

        $this->assertSame([], $this->pushes());
    }

    public function test_without_firebase_credentials_nothing_is_sent(): void
    {
        config(['services.fcm.credentials' => $this->credentials.'-missing']);
        $this->app->forgetInstance(\App\Services\Push\FcmSender::class);
        $this->actingAs($this->yamen())
            ->postJson(route('mobile.push-devices.store'), ['token' => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('serverReady', false);

        $this->submitTicket();

        Http::assertNothingSent();
    }
}
