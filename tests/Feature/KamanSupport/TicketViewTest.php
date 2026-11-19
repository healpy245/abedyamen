<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * A ticket the way the POS sends it: the level and the callback line in the summary, the callback
     * line first and "voice 30s" last in the details, and a voice note on disk.
     */
    private function posTicket(string $words = 'الطابعة لا تطبع طلبات المطبخ', bool $voice = true, string $id = 'KAM-7'): SupportTicket
    {
        $extra = ['level' => 'danger_now', 'appVersion' => '3.4.1', 'voiceSeconds' => 30];
        if ($voice) {
            Storage::disk('local')->put('kaman-support/tickets/'.$id.'/voice.m4a', 'audio-bytes');
            $extra['audioPath'] = 'kaman-support/tickets/'.$id.'/voice.m4a';
        }
        $ticket = SupportTicket::query()->create([
            'public_id' => $id,
            'restaurant' => 'thex',
            'restaurant_name' => 'The X',
            'cashier_name' => 'Huda',
            'device_id' => 'pos-1',
            'summary' => trim('خطر الآن: للتواصل: 0521234567 '.$words),
            'details' => implode("\n", array_filter(['للتواصل: 0521234567', $words, $voice ? 'voice 30s' : null])),
            'conversation' => ['user: nothing prints', 'bot: checking the printers'],
            'extra' => $extra,
            'status' => 'open',
        ]);
        $ticket->events()->create(['type' => 'created', 'message' => $ticket->summary, 'actor_type' => 'pos', 'actor_name' => 'Huda']);

        return $ticket;
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => 'yamen@kaman.rest', 'is_admin' => true, 'projects' => ['kaman-support', 'app-development']]);
    }

    public function test_the_report_is_the_cashiers_words_only(): void
    {
        $ticket = $this->posTicket();
        $this->assertSame('الطابعة لا تطبع طلبات المطبخ', $ticket->report());
        $this->assertSame(30, $ticket->mediaSeconds('voice'));

        $voiceOnly = $this->posTicket('', id: 'KAM-8');
        $this->assertSame('', $voiceOnly->report());
    }

    public function test_the_ticket_modal_shows_the_description_once_with_the_logo(): void
    {
        $ticket = $this->posTicket();
        \Illuminate\Support\Facades\Cache::put('support.restaurant-logo.thex', 'https://thex.kaman.rest/logo.png', 60);

        $json = $this->actingAs($this->admin())->getJson('/kaman-support/tickets/'.$ticket->public_id)->assertOk()->json();

        $this->assertSame('الطابعة لا تطبع طلبات المطبخ', $json['ticket']['report']);
        $this->assertSame('https://thex.kaman.rest/logo.png', $json['ticket']['logo']);
        $this->assertSame('Huda', $json['ticket']['cashier']);
        // The "created" event repeated the summary; it no longer does.
        $this->assertSame('', $json['ticket']['events'][0]['message']);
        $this->assertStringContainsString('الطابعة لا تطبع طلبات المطبخ', $json['ticket']['escalateText']);
    }

    public function test_escalating_carries_everything_and_the_voice_note(): void
    {
        $ticket = $this->posTicket();
        $user = $this->admin();
        $text = $ticket->escalationText();

        foreach (['الطابعة لا تطبع طلبات المطبخ', 'The X (thex)', '052-123-4567', 'https://wa.me/972521234567', 'Huda', 'pos-1', '3.4.1', 'KAM-7', '/kaman-support/inbox?ticket=KAM-7', 'user: nothing prints'] as $part) {
            $this->assertStringContainsString($part, $text);
        }
        $this->assertStringNotContainsString('voice 30s', $text);

        // Sent with an empty box the server still writes the whole ticket.
        $this->actingAs($user)->postJson('/kaman-support/tickets/'.$ticket->public_id.'/escalate', ['description' => ''])->assertOk();

        $dev = AppDevelopmentTicket::query()->firstOrFail();
        $this->assertSame($text, $dev->description);
        $attachment = $dev->attachments()->firstOrFail();
        $this->assertSame('voice-KAM-7.m4a', $attachment->original_name);
        $this->assertTrue($attachment->isAudio());
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($dev->id, $ticket->fresh()->escalated_ticket_id);
    }

    public function test_a_voice_only_ticket_says_to_listen(): void
    {
        $ticket = $this->posTicket('');

        $this->assertStringStartsWith(__('kaman-support.no_written_report'), $ticket->escalationText());
        $this->actingAs($this->admin())->get('/kaman-support/inbox')
            ->assertOk()
            ->assertSee('data-escalate-text', false);
    }
}
