<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MansourContact;
use App\Models\User;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\MansourLines;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MansourLinesTest extends TestCase
{
    use RefreshDatabase;

    private const LINE_A = 'https://7103.api.greenapi.com/waInstance111/sendMessage/tokA';

    private const LINE_B = 'https://7103.api.greenapi.com/waInstance222/sendMessage/tokB';

    private const LINE_C = 'https://7103.api.greenapi.com/waInstance333/sendMessage/tokC';

    public function test_settings_save_many_lines_and_keep_one_live(): void
    {
        [$user, $bot] = $this->bot();

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form([self::LINE_A, self::LINE_B, self::LINE_C, self::LINE_B]))
            ->assertRedirect();

        $bot->refresh();
        $lines = app(MansourLines::class)->lines($bot);
        $this->assertSame(['111', '222', '333'], array_column($lines, 'id'));
        $this->assertSame(['live', 'ready', 'ready'], array_column($lines, 'status'));
        $this->assertSame(self::LINE_A, $bot->greenapi_url);

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form([self::LINE_A, self::LINE_B, self::LINE_C]) + [
                'greenapi_active_present' => '1',
                'greenapi_active' => ['111', '333'],
            ])
            ->assertRedirect();

        $bot->refresh();
        $lines = app(MansourLines::class)->lines($bot);
        $this->assertTrue(app(MansourLines::class)->isOn($lines[0]));
        $this->assertFalse(app(MansourLines::class)->isOn($lines[1]));
        $this->assertTrue(app(MansourLines::class)->isOn($lines[2]));
        $this->assertSame(self::LINE_A, $bot->greenapi_url);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('#333', false)
            ->assertSee('يعمل')
            ->assertSee('متوقف')
            ->assertSee('تحديد الكل')
            ->assertSee('data-bulk="on"', false)
            ->assertSee('data-bulk="off"', false);
    }

    public function test_settings_page_renders_stored_lines_without_calling_whatsapp(): void
    {
        [$user, $bot] = $this->bot();
        Http::fake();

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form([self::LINE_A]))
            ->assertRedirect();

        $bot->refresh();
        $settings = $bot->integration_settings;
        $settings['greenapi_lines'][0]['avatar'] = 'data:image/jpeg;base64,'.str_repeat('A', 4000);
        $settings['greenapi_lines'][0]['name'] = 'خط محفوظ';
        $bot->forceFill(['integration_settings' => $settings])->save();
        Http::fake();

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('خط محفوظ', false)
            ->assertDontSee('data:image/jpeg;base64', false);

        Http::assertNothingSent();
    }

    public function test_settings_keep_a_line_past_twelve_and_a_messy_paste(): void
    {
        [$user, $bot] = $this->bot();
        $urls = [];
        for ($i = 1; $i <= 13; $i++) {
            $urls[] = 'https://7103.api.greenapi.com/waInstance'.(710300000 + $i).'/sendMessage/tok'.$i;
        }
        $urls[] = "الخط الجديد\nhttps://7103.api.greenapi.com/waInstance710399999/sendMessage/tokNew \n";

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form($urls))
            ->assertRedirect()
            ->assertSessionHas('status', 'تم الحفظ. الخطوط: 14');

        $bot->refresh();
        $lines = app(MansourLines::class)->lines($bot);
        $this->assertCount(14, $lines);
        $this->assertContains('710399999', array_column($lines, 'id'));

        $this->actingAs($user)
            ->from(route('ai-chatbot.workspace.settings', $bot))
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form([
                $urls[0],
                'not a green api link',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('greenapi_urls');

        $this->actingAs($user)
            ->followingRedirects()
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form([
                $urls[0],
                'not a green api link',
            ]))
            ->assertSee('not a green api link', false)
            ->assertSee('waInstance', false);
    }

    public function test_selected_lines_turn_on_and_off_together(): void
    {
        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B, self::LINE_C]);
        $lines->stateChanged($bot->fresh(), '333', 'blocked');

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.active', $bot), [
                'line_ids' => ['111', '222', '333'],
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ids', ['111', '222']);

        $bot->refresh();
        $rows = $lines->lines($bot);
        $this->assertTrue($lines->isOn($rows[0]));
        $this->assertTrue($lines->isOn($rows[1]));
        $this->assertFalse($lines->isOn($rows[2]));

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.active', $bot), [
                'line_ids' => ['222'],
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('ids', ['222']);

        $rows = $lines->lines($bot->fresh());
        $this->assertTrue($lines->isOn($rows[0]));
        $this->assertFalse($lines->isOn($rows[1]));
        $this->assertFalse($lines->isOn($rows[2]));
    }

    public function test_a_blocked_line_does_not_send_the_rest_from_another_number(): void
    {
        Http::fake([
            '*waInstance111/sendMessage/*' => Http::response(['message' => 'blocked'], 400),
            '*waInstance111/getStateInstance/*' => Http::response(['stateInstance' => 'blocked'], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);
        [$user, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B]);

        $first = $this->contact($bot, 'أحمد', '972501111111');
        $second = $this->contact($bot, 'ليلى', '972502222222');

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), ['contact_ids' => [$first->id, $second->id], 'line' => '111'])
            ->assertRedirect();

        $bot->refresh();
        $this->assertNotSame(self::LINE_B, $bot->greenapi_url);
        $lines = app(MansourLines::class)->lines($bot);
        $this->assertSame(['blocked', 'ready'], array_column($lines, 'status'));
        $this->assertSame('blocked', $lines[0]['reason']);
        $this->assertFalse(app(MansourLines::class)->isOn($lines[1]));

        $this->assertNull($first->refresh()->triggered_at);
        $this->assertNull($second->refresh()->triggered_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'waInstance222/'));

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('محظور');
    }

    public function test_state_webhook_blocks_only_that_line(): void
    {
        [, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B, self::LINE_C]);
        $token = app(ChatbotGreenApiService::class)->ensureWebhookToken($bot);

        $this->postJson(route('ai-chatbot.greenapi.webhook', $token), [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 111],
            'stateInstance' => 'yellowCard',
        ])->assertOk();

        $bot->refresh();
        $lines = app(MansourLines::class)->lines($bot);
        $this->assertSame(['blocked', 'ready', 'ready'], array_column($lines, 'status'));
        $this->assertFalse(app(MansourLines::class)->isOn($lines[1]));
        $this->assertNotSame(self::LINE_B, $bot->greenapi_url);

        $this->postJson(route('ai-chatbot.greenapi.webhook', $token), [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 111],
            'stateInstance' => 'authorized',
        ])->assertOk();

        $lines = app(MansourLines::class)->lines($bot->refresh());
        $this->assertSame(['ready', 'ready', 'ready'], array_column($lines, 'status'));
        $this->assertFalse(app(MansourLines::class)->isOn($lines[0]));
    }

    public function test_conversation_stays_on_its_own_line_until_that_line_is_blocked(): void
    {
        [, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $conversation = $bot->conversations()->create([
            'user_id' => $bot->user_id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972503333333@c.us',
            'title' => 'سامي',
        ]);

        $lines->rememberInbound($bot, $conversation, '222');
        $this->assertSame(self::LINE_B, $lines->urlFor($bot->refresh(), $conversation->refresh()));

        $lines->markBlocked($bot, '222', 'blocked');
        $conversation->refresh();
        $this->assertSame('', $lines->urlFor($bot->refresh(), $conversation));
        $this->assertSame('222', $conversation->metadata['mansour_line'] ?? null);
    }

    public function test_each_line_shows_its_own_webhook_and_whatsapp_name(): void
    {
        Http::fake([
            '*getWaSettings*' => Http::response([
                'stateInstance' => 'authorized',
                'phone' => '972586769207',
                'avatar' => '',
                'base64Avatar' => '',
            ], 200),
            '*getContactInfo*' => Http::response([
                'name' => '~مركز اتصالات',
                'base64Avatar' => base64_encode('photo'),
            ], 200),
        ]);

        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $this->assertSame('مركز اتصالات', $lines->profileFor(self::LINE_A)['name']);

        $lines->refreshProfiles($bot->fresh());
        $this->assertSame('مركز اتصالات', $lines->lines($bot->fresh())[0]['name']);

        $token = app(ChatbotGreenApiService::class)->ensureWebhookToken($bot->fresh());
        $this->postJson(route('ai-chatbot.greenapi.line-webhook', ['token' => $token, 'line' => '111']), [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 111],
            'stateInstance' => 'authorized',
        ])->assertOk();

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('مركز اتصالات', false)
            ->assertSee('+972586769207', false)
            ->assertSee('/ai-chatbot/webhook/greenapi/'.$token.'/111', false)
            ->assertSee('/ai-chatbot/webhook/greenapi/'.$token.'/222', false)
            ->assertDontSee('نفس الرابط لكل الخطوط', false);
    }

    public function test_chats_page_switches_lines_and_keeps_each_lines_chats(): void
    {
        [$user, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B]);
        $this->chat($bot, 'أحمد', '111');
        $this->chat($bot, 'ليلى', '222');
        $this->chat($bot, 'سامي', null);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertSee('تبديل الرقم', false)
            ->assertSee('كل الأرقام', false)
            ->assertSee('#111', false)
            ->assertSee('#222', false)
            ->assertSee('أحمد', false)
            ->assertSee('ليلى', false)
            ->assertSee('سامي', false)
            ->assertDontSee('إيقاف هذا الرقم', false);

        $ahmad = ChatbotConversation::query()->where('contact_name', 'أحمد')->firstOrFail();
        $pane = $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.conversations.show', [
                'instance' => $bot,
                'conversation' => $ahmad,
                'pane' => 1,
                'line' => 'all',
            ]))
            ->assertOk()
            ->assertJsonPath('id', $ahmad->id);
        $this->assertStringContainsString('أحمد', (string) $pane->json('html'));
        $this->assertStringContainsString('workspace-chat-live', (string) $pane->json('html'));

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', ['instance' => $bot, 'line' => '222']))
            ->assertOk()
            ->assertSee('ليلى', false)
            ->assertSee('تشغيل', false)
            ->assertDontSee('أحمد', false)
            ->assertDontSee('سامي', false);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.conversations.poll', ['instance' => $bot, 'line' => '222']))
            ->assertOk()
            ->assertJsonPath('conversations.0.display_name', 'ليلى')
            ->assertJsonCount(1, 'conversations');

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', ['instance' => $bot, 'line' => 'all']))
            ->assertOk()
            ->assertSee('كل الأرقام', false)
            ->assertSee('أحمد', false)
            ->assertSee('ليلى', false)
            ->assertSee('سامي', false)
            ->assertDontSee('إيقاف هذا الرقم', false);

        $names = $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.conversations.poll', ['instance' => $bot, 'line' => 'all']))
            ->assertOk()
            ->json('conversations');
        $this->assertEqualsCanonicalizing(
            ['أحمد', 'ليلى', 'سامي'],
            array_column($names, 'display_name'),
        );
    }

    public function test_each_line_triggers_on_its_own_and_keeps_its_own_chat(): void
    {
        Http::fake(['*' => Http::response(['idMessage' => '1'], 200)]);
        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $lines->setActive($bot->fresh(), '222', true);
        $contact = $this->contact($bot, 'أحمد', '972501111111');

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$contact->id],
                'line' => '111',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$contact->id],
                'line' => '222',
            ])
            ->assertRedirect();

        $chats = $bot->conversations()->orderBy('id')->get();
        $this->assertCount(2, $chats);
        $this->assertSame(['111', '222'], $chats->map(fn ($chat) => $chat->metadata['mansour_line'] ?? null)->all());
        $this->assertSame(1, $chats[0]->messages()->count());
        $this->assertSame(1, $chats[1]->messages()->count());
        $this->assertSame(['111', '222'], array_map('strval', array_keys($contact->refresh()->triggered_lines)));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'waInstance111/sendMessage'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'waInstance222/sendMessage'));
    }

    public function test_the_send_list_is_one_list_and_sent_is_per_line(): void
    {
        [$user, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B]);
        $sent = $this->contact($bot, 'أحمد', '972501111111');
        $sent->forceFill([
            'triggered_at' => now(),
            'triggered_lines' => ['111' => now()->toIso8601String()],
        ])->save();
        $this->contact($bot, 'ليلى', '972502222222');

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.contacts', $bot))
            ->assertOk()
            ->assertJsonCount(2, 'contacts')
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('contacts.0.sent_lines', ['111'])
            ->assertJsonPath('contacts.0.triggered', true)
            ->assertJsonPath('contacts.1.triggered', false)
            ->assertJsonPath('lines.0.cap', MansourLines::DAY_CAP)
            ->assertJsonPath('lines.1.cap', MansourLines::DAY_CAP);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.contacts', $bot).'?line=222')
            ->assertOk()
            ->assertJsonPath('contacts.0.triggered', true);
    }

    public function test_an_off_line_cannot_be_triggered(): void
    {
        Http::fake(['*' => Http::response(['idMessage' => '1'], 200)]);
        [$user, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B]);
        $contact = $this->contact($bot, 'أحمد', '972501111111');

        $this->actingAs($user)
            ->from(route('ai-chatbot.workspace.conversations', $bot))
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$contact->id],
                'line' => '222',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('trigger');

        $this->assertNull($contact->refresh()->triggered_at);
        $this->assertSame(0, $bot->conversations()->count());
        Http::assertNothingSent();
    }

    public function test_untriggered_and_other_line_messages_are_ignored(): void
    {
        [, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $lines->setActive($bot->fresh(), '222', true);
        $contact = $this->contact($bot, 'أحمد', '972501111111');
        $contact->forceFill([
            'chat_id' => '972501111111@c.us',
            'triggered_at' => now(),
            'triggered_lines' => ['111' => now()->toIso8601String()],
        ])->save();
        $chat = ChatbotConversation::query()->create([
            'user_id' => $bot->user_id,
            'instance_id' => $bot->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972501111111@c.us',
            'contact_phone' => '972501111111',
            'title' => 'أحمد',
            'metadata' => ['mansour_line' => '111'],
        ]);
        $token = app(ChatbotGreenApiService::class)->ensureWebhookToken($bot->fresh());

        $this->postJson(route('ai-chatbot.greenapi.line-webhook', ['token' => $token, 'line' => '111']), $this->inbound('972509999001@c.us', 'غريب', 'in-stranger'))
            ->assertOk()
            ->assertJsonPath('ignored', true);
        $this->postJson(route('ai-chatbot.greenapi.line-webhook', ['token' => $token, 'line' => '222']), $this->inbound('972501111111@c.us', 'أحمد', 'in-other-line'))
            ->assertOk()
            ->assertJsonPath('ignored', true);
        $this->postJson(route('ai-chatbot.greenapi.webhook', $token), $this->inbound('972501111111@c.us', 'أحمد', 'in-no-line'))
            ->assertOk()
            ->assertJsonPath('ignored', true);

        $this->assertSame(1, $bot->conversations()->count());
        $this->assertSame(0, $chat->messages()->count());
        $this->assertSame('111', $chat->refresh()->metadata['mansour_line']);
    }

    public function test_a_reply_on_the_triggered_line_stays_on_that_line(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [, $bot] = $this->bot();
        app(MansourLines::class)->sync($bot, [self::LINE_A, self::LINE_B]);
        $contact = $this->contact($bot, 'أحمد', '972501111111');
        $contact->forceFill([
            'chat_id' => '972501111111@c.us',
            'triggered_at' => now(),
            'triggered_lines' => ['111' => now()->toIso8601String()],
        ])->save();
        $chat = ChatbotConversation::query()->create([
            'user_id' => $bot->user_id,
            'instance_id' => $bot->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972501111111@c.us',
            'contact_phone' => '972501111111',
            'title' => 'أحمد',
            'metadata' => ['mansour_line' => '111'],
        ]);
        $token = app(ChatbotGreenApiService::class)->ensureWebhookToken($bot->fresh());

        $this->postJson(route('ai-chatbot.greenapi.line-webhook', ['token' => $token, 'line' => '111']), $this->inbound('972501111111@c.us', 'أحمد', 'in-own-line'))
            ->assertOk()
            ->assertJsonMissing(['ignored' => true]);

        $this->assertSame(1, $bot->conversations()->count());
        $this->assertSame('111', $chat->refresh()->metadata['mansour_line']);
        $this->assertSame(1, $chat->messages()->count());
    }

    public function test_each_line_shows_triggered_chats_and_restriction_risk(): void
    {
        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $this->triggered($bot, '111', 2);
        $this->triggered($bot, '222', 5);
        $this->triggered($bot, '111', 1, now()->subDays(2));
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'قديم',
            'phone' => '972500000001',
            'phone_normalized' => '972500000001',
            'chat_id' => '972500000001@c.us',
            'triggered_at' => now(),
        ]);

        $usage = $lines->usage($bot->fresh());
        $this->assertSame(4, $usage['111']['triggered']);
        $this->assertSame(3, $usage['111']['today']);
        $this->assertSame(13, $usage['111']['percent']);
        $this->assertSame(MansourLines::DAY_CAP, $usage['111']['cap']);
        $this->assertSame('low', $usage['111']['level']);
        $this->assertSame(5, $usage['222']['triggered']);
        $this->assertSame(5, $usage['222']['today']);
        $this->assertSame(21, $usage['222']['percent']);
        $this->assertSame('low', $usage['222']['level']);
        $this->assertSame('منخفض', $usage['222']['level_label']);
        $this->assertSame(0, $lines->dangerPercent(0));
        $this->assertSame(100, $lines->dangerPercent(MansourLines::DAY_CAP));

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertSee('data-line-stat="111"', false)
            ->assertSee('data-line-stat="222"', false)
            ->assertSee('id="farz-pace"', false)
            ->assertSee('13%', false)
            ->assertSee('21%', false)
            ->assertSee('3/'.MansourLines::DAY_CAP, false)
            ->assertSee('5/'.MansourLines::DAY_CAP, false)
            ->assertSee('محادثة', false)
            ->assertDontSee('تنبيه', false);
    }

    public function test_suspended_state_shows_on_the_line_card(): void
    {
        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);

        Http::fake([
            '*/waInstance111/getWaSettings/*' => Http::response(['stateInstance' => 'suspended', 'phone' => '972505133749']),
            '*/waInstance222/getWaSettings/*' => Http::response(['stateInstance' => 'authorized', 'phone' => '972501111111']),
        ]);
        $lines->refreshStates($bot->fresh());

        $stored = $lines->lines($bot->fresh());
        $this->assertSame('blocked', $stored[0]['status']);
        $this->assertSame('suspended', $stored[0]['reason']);
        $this->assertFalse($lines->isOn($stored[0]));
        $this->assertSame('ready', $stored[1]['status']);
        $this->assertSame('972505133749', $stored[0]['phone']);
        $this->assertSame('972501111111', $stored[1]['phone']);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('موقوف', false);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.lines.state', $bot))
            ->assertOk()
            ->assertJsonPath('lines.0.label', 'موقوف')
            ->assertJsonPath('lines.0.reason', 'suspended')
            ->assertJsonPath('lines.1.label', 'متوقف');
    }

    public function test_logged_out_or_restricted_line_shows_a_red_alert(): void
    {
        [$user, $bot] = $this->bot();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B, self::LINE_C]);
        $lines->stateChanged($bot->fresh(), '111', 'notAuthorized');
        $lines->stateChanged($bot->fresh(), '222', 'blocked');
        $lines->stateChanged($bot->fresh(), '333', 'yellowCard');

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertSee('farz-dead', false)
            ->assertSee('تنبيه', false)
            ->assertSee('غير مسجّل', false)
            ->assertSee('محظور', false)
            ->assertSee('إنذار واتساب', false);

        $payload = $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.conversations.poll', $bot))
            ->assertOk()
            ->json('lines');

        $this->assertSame(
            ['غير مسجّل', 'محظور', 'إنذار واتساب'],
            array_column($payload, 'alert'),
        );
        $this->assertSame(['dead', 'dead', 'dead'], array_column($payload, 'level'));
        $this->assertSame([0, 0, 0], array_column($payload, 'percent'));
        $this->assertSame(MansourLines::DAY_CAP, $payload[0]['cap']);
    }

    /**
     * @return array<string, mixed>
     */
    private function inbound(string $chatId, string $name, string $messageId): array
    {
        return [
            'typeWebhook' => 'incomingMessageReceived',
            'senderData' => ['chatId' => $chatId, 'senderName' => $name],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => ['textMessage' => 'مرحبا'],
                'idMessage' => $messageId,
            ],
        ];
    }

    /**
     * @return array{0: User, 1: ChatbotInstance}
     */
    private function bot(): array
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();

        return [$user, ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail()];
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, mixed>
     */
    private function form(array $urls): array
    {
        return [
            'name' => 'بوت الفرز',
            'greenapi_urls' => $urls,
            'system_prompt' => 'اسأل عن التصويت بهدوء.',
            'opening_message' => 'يسعد اوقاتك',
        ];
    }

    private function chat(ChatbotInstance $bot, string $name, ?string $line): ChatbotConversation
    {
        return ChatbotConversation::query()->create([
            'user_id' => $bot->user_id,
            'instance_id' => $bot->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '97250'.substr(md5($name), 0, 7).'@c.us',
            'contact_name' => $name,
            'contact_phone' => '97250'.substr(md5($name), 0, 7),
            'last_message_at' => now(),
            'metadata' => $line === null ? [] : ['mansour_line' => $line],
        ]);
    }

    private function triggered(ChatbotInstance $bot, string $line, int $count, ?\Illuminate\Support\Carbon $at = null): void
    {
        $at ??= now();
        static $seq = 0;
        for ($i = 0; $i < $count; $i++) {
            $phone = '9725'.str_pad((string) (++$seq), 7, '0', STR_PAD_LEFT);
            MansourContact::query()->create([
                'instance_id' => $bot->id,
                'name' => $line.'-'.$i,
                'phone' => $phone,
                'phone_normalized' => $phone,
                'chat_id' => $phone.'@c.us',
                'triggered_at' => $at,
                'triggered_lines' => [$line => $at->toIso8601String()],
            ]);
        }
    }

    private function contact(ChatbotInstance $bot, string $name, string $phone): MansourContact
    {
        return MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => $name,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'chat_id' => $phone.'@c.us',
        ]);
    }
}
