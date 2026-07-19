<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When the dashboard pauses the WhatsApp webhook, the AI must not reply —
 * and after resume it must not process messages from the inactive window.
 */
class WhatsAppWebhookPauseTest extends TestCase
{
    private const WEBHOOK_ACTIVE_CACHE_KEY = 'whatsapp_webhook_active';

    private const WEBHOOK_RESUME_AFTER_CACHE_KEY = 'whatsapp_webhook_resume_after';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        Cache::flush();
    }

    public function test_deactivated_webhook_does_not_call_ai_or_send_replies(): void
    {
        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, false);

        $response = $this->postJson('/whatsapp-bot/webhook', $this->incomingPayload(
            messageId: 'msg-while-off',
            text: 'hello while paused',
            timestamp: now()->timestamp,
        ));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Webhook is deactivated.',
            ]);

        Http::assertNothingSent();
    }

    public function test_deactivated_webhook_does_not_expose_message_content_in_events(): void
    {
        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, false);

        $this->postJson('/whatsapp-bot/webhook', $this->incomingPayload(
            messageId: 'msg-secret-while-off',
            text: 'secret lead details',
            timestamp: now()->timestamp,
        ))->assertOk();

        $events = Cache::get('whatsapp_webhook_events', []);
        $this->assertNotEmpty($events);
        $last = end($events);

        $this->assertSame('deactivated', $last['status']);
        $this->assertNull($last['incoming']);
        $this->assertStringNotContainsString('secret lead details', json_encode($events));
    }

    public function test_message_seen_while_deactivated_is_not_processed_after_reactivation(): void
    {
        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, false);

        $payload = $this->incomingPayload(
            messageId: 'msg-redeliver',
            text: 'please reply to this backlog',
            timestamp: now()->subMinutes(2)->timestamp,
        );

        $this->postJson('/whatsapp-bot/webhook', $payload)->assertOk()
            ->assertJsonPath('ignored', true);

        // Simulate dashboard reactivation: only messages after this watermark are eligible.
        $resumeAt = now()->timestamp;
        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, true);
        Cache::forever(self::WEBHOOK_RESUME_AFTER_CACHE_KEY, $resumeAt);

        $this->postJson('/whatsapp-bot/webhook', $payload)->assertOk()
            ->assertJsonPath('ignored', true);

        Http::assertNothingSent();
    }

    public function test_message_timestamped_during_inactive_window_is_ignored_after_resume(): void
    {
        $inactiveAt = now()->subMinutes(3)->timestamp;
        $resumeAt = now()->subMinute()->timestamp;

        Cache::forever(self::WEBHOOK_ACTIVE_CACHE_KEY, true);
        Cache::forever(self::WEBHOOK_RESUME_AFTER_CACHE_KEY, $resumeAt);

        $response = $this->postJson('/whatsapp-bot/webhook', $this->incomingPayload(
            messageId: 'msg-from-inactive-window',
            text: 'sent while bot was off',
            timestamp: $inactiveAt,
        ));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'ignored' => true,
                'reason' => 'Ignored message sent while webhook was inactive.',
            ]);

        Http::assertNothingSent();

        $events = Cache::get('whatsapp_webhook_events', []);
        $last = end($events);
        $this->assertNull($last['incoming']);
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingPayload(string $messageId, string $text, int $timestamp): array
    {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => $timestamp,
            'idMessage' => $messageId,
            'senderData' => [
                'chatId' => '972501112223@c.us',
            ],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => [
                    'textMessage' => $text,
                ],
            ],
        ];
    }
}
