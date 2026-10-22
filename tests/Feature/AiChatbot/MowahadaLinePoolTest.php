<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\MowahadaLine;
use App\Models\User;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourLines;
use App\Services\AiChatbot\MowahadaLinePool;
use Database\Seeders\MowahadaViewerSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MowahadaLinePoolTest extends TestCase
{
    use RefreshDatabase;

    private const LINE_A = 'https://7103.api.greenapi.com/waInstance111/sendMessage/tokA';

    private const LINE_B = 'https://7103.api.greenapi.com/waInstance222/sendMessage/tokB';

    public function test_catalog_is_shared_and_each_bot_picks_its_numbers(): void
    {
        [$user, $bot] = $this->bots();
        $second = app(MansourBotService::class)->copy($bot, $user);
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A]);
        $lines->sync($bot->fresh(), [self::LINE_A], null, []);
        app(MowahadaLinePool::class)->importFromBots();

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'urls' => self::LINE_B."\n".self::LINE_A,
            ])
            ->assertRedirect(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]));

        $this->assertSame(['111', '222'], MowahadaLine::query()->orderBy('wa_instance')->pluck('wa_instance')->all());

        MowahadaLine::query()->where('wa_instance', '222')->update([
            'status' => MansourLines::BLOCKED,
            'reason' => 'suspended',
            'phone' => '972546227802',
        ]);

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $second), $this->form($second, ['222'], []))
            ->assertRedirect();

        $second->refresh();
        $picked = $lines->lines($second);
        $this->assertSame(['222'], array_column($picked, 'id'));
        $this->assertFalse($lines->isOn($picked[0]));
        $this->assertSame('suspended', $picked[0]['reason']);
        $this->assertSame('972546227802', $picked[0]['phone']);
        $this->assertSame(['111'], array_column($lines->lines($bot->fresh()), 'id'));

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $second))
            ->assertOk()
            ->assertSee('مصدر الأرقام', false)
            ->assertSee('أرقام واتساب لهذا البوت', false)
            ->assertSee('نقل لهذا البوت', false)
            ->assertSee('موقوف', false)
            ->assertSee('يعمل الآن على', false)
            ->assertSee('name="lines_keep"', false)
            ->assertDontSee('id="farz-line-add"', false);

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('إضافة للمصدر', false)
            ->assertSee('#111', false)
            ->assertSee('يعمل على: '.$bot->name, false)
            ->assertSee('غير مربوط بأي بوت', false);

        $this->actingAs($user)
            ->delete(route('ai-chatbot.farz.numbers.destroy', '222'), ['bot' => $bot->id])
            ->assertRedirect();

        $this->assertNull(MowahadaLine::query()->where('wa_instance', '222')->first());
        $this->assertSame([], $lines->lines($second->fresh()));
        $this->assertSame(['111'], array_column($lines->lines($bot->fresh()), 'id'));
    }

    public function test_paused_number_stays_paused_when_the_bot_is_saved(): void
    {
        [$user, $bot] = $this->bots();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B], null, ['111']);
        app(MowahadaLinePool::class)->remember([self::LINE_A, self::LINE_B]);

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), $this->form($bot, ['111', '222'], ['111']))
            ->assertRedirect();

        $stored = $lines->lines($bot->fresh());
        $this->assertSame(['111', '222'], array_column($stored, 'id'));
        $this->assertTrue($lines->isOn($stored[0]));
        $this->assertFalse($lines->isOn($stored[1]));
    }

    public function test_old_url_save_still_fills_the_catalog(): void
    {
        [$user, $bot] = $this->bots();

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), [
                'name' => $bot->name,
                'greenapi_urls' => [self::LINE_A],
                'system_prompt' => 'اسأل بهدوء.',
                'opening_message' => 'يسعد اوقاتك',
            ])
            ->assertRedirect();

        $this->assertNotNull(MowahadaLine::query()->where('wa_instance', '111')->first());
    }

    public function test_several_links_save_together_and_each_shows_a_webhook(): void
    {
        [$user, $bot] = $this->bots();

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'links' => [self::LINE_A, '', self::LINE_B],
            ])
            ->assertRedirect(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]));

        $this->assertSame(['111', '222'], MowahadaLine::query()->orderBy('wa_instance')->pluck('wa_instance')->all());
        $token = app(\App\Services\AiChatbot\ChatbotGreenApiService::class)->ensureWebhookToken($bot->fresh());

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('سطر جديد', false)
            ->assertSee('نسخ', false)
            ->assertSee('/ai-chatbot/webhook/greenapi/'.$token.'/111', false)
            ->assertSee('/ai-chatbot/webhook/greenapi/'.$token.'/222', false);
    }

    public function test_a_bad_paste_stays_on_the_numbers_page(): void
    {
        [$user, $bot] = $this->bots();

        $this->actingAs($user)
            ->from(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'urls' => "not a green api link\n".self::LINE_A,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('urls');

        $this->actingAs($user)
            ->followingRedirects()
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'ids' => ['71072772143'],
                'tokens' => ['short'],
            ])
            ->assertSee('71072772143', false)
            ->assertSee('short', false)
            ->assertSee('اكتب رقم المثيل وتوكن API', false);
    }

    public function test_viewer_can_open_the_catalog_but_cannot_change_it(): void
    {
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([self::LINE_A]);
        $this->seed(MowahadaViewerSeeder::class);
        $viewer = User::query()->where('email', MowahadaViewerSeeder::EMAIL)->firstOrFail();

        $this->actingAs($viewer)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('#111', false)
            ->assertDontSee('إضافة للمصدر', false);

        $this->actingAs($viewer)
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'urls' => self::LINE_B,
            ])
            ->assertForbidden();
    }

    public function test_other_bots_keep_their_own_url_editor(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $owner = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();
        $bot = ChatbotInstance::query()->create([
            'user_id' => $owner->id,
            'name' => 'بوت تجريبي',
            'system_prompt' => 'مرحبا',
            'integration_type' => 'mansour',
            'integration_settings' => ['opening_message' => 'أهلا'],
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('إضافة خط', false)
            ->assertDontSee('pool_present', false)
            ->assertDontSee('مصدر أرقام', false);
    }

    public function test_a_new_line_connects_from_the_instance_id_and_api_token(): void
    {
        Http::fake([
            'https://7107.api.greenapi.com/waInstance71072772143/getWaSettings/*' => Http::response([
                'stateInstance' => 'authorized',
                'phone' => '972505183718',
            ]),
        ]);
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([self::LINE_A]);
        $token = '0123456789abcdef0123456789abcdef01234567';

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'ids' => ['', '71072772143'],
                'tokens' => ['', $token],
            ])
            ->assertRedirect(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]));

        $added = MowahadaLine::query()->where('wa_instance', '71072772143')->firstOrFail();
        $this->assertSame(
            'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/'.$token,
            $added->url,
        );
        $this->assertSame('972505183718', $added->phone);
        $this->assertSame(self::LINE_A, MowahadaLine::query()->where('wa_instance', '111')->value('url'));

        $hook = app(\App\Services\AiChatbot\ChatbotGreenApiService::class)->ensureWebhookToken($bot->fresh());
        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('رقم المثيل', false)
            ->assertSee('توكن API', false)
            ->assertSee('/ai-chatbot/webhook/greenapi/'.$hook.'/71072772143', false)
            ->assertSee(self::LINE_A, false);
    }

    public function test_a_rejected_token_does_not_change_the_catalog(): void
    {
        Http::fake([
            'https://7107.api.greenapi.com/*' => Http::response(['error' => 'denied'], 401),
        ]);
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([self::LINE_A]);

        $this->actingAs($user)
            ->from(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->post(route('ai-chatbot.farz.numbers.store'), [
                'bot' => $bot->id,
                'ids' => ['71072772143'],
                'tokens' => ['0123456789abcdef0123456789abcdef01234567'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('tokens');

        $this->assertNull(MowahadaLine::query()->where('wa_instance', '71072772143')->first());
        $this->assertSame(self::LINE_A, MowahadaLine::query()->where('wa_instance', '111')->value('url'));
    }

    public function test_warm_sends_the_typed_message_from_the_chosen_lines(): void
    {
        $url = 'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/0123456789abcdef0123456789abcdef01234567';
        Http::fake([
            'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/*' => Http::response(['idMessage' => 'warm-1'], 200),
        ]);
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([$url]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.warm'), [
                'bot' => $bot->id,
                'lines' => ['71072772143'],
                'phones' => "0521111111\n972522222222",
                'message' => 'يسعد اوقاتك',
            ])
            ->assertRedirect(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertSessionHas('status', 'التسخين: نجح 2، فشل 0.');

        $this->assertSame(0, ChatbotConversation::query()->count());
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/0123456789abcdef0123456789abcdef01234567'
                && $request['chatId'] === '972521111111@c.us'
                && $request['message'] === 'يسعد اوقاتك';
        });
        Http::assertSentCount(2);
    }

    public function test_a_bad_warm_phone_sends_nothing(): void
    {
        Http::fake();
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([
            'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/0123456789abcdef0123456789abcdef01234567',
        ]);

        $this->actingAs($user)
            ->from(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->post(route('ai-chatbot.farz.numbers.warm'), [
                'bot' => $bot->id,
                'lines' => ['71072772143'],
                'phones' => '123',
                'message' => 'مرحبا',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('phones');

        Http::assertNothingSent();
    }

    public function test_editing_a_line_refreshes_its_token_and_can_change_the_instance(): void
    {
        $old = 'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/0123456789abcdef0123456789abcdef01234567';
        $token = 'abcdef0123456789abcdef0123456789abcdef01';
        Http::fake([
            'https://7107.api.greenapi.com/waInstance71072772143/getWaSettings/*' => Http::response([
                'stateInstance' => 'authorized',
                'phone' => '972505183718',
            ]),
            'https://7107.api.greenapi.com/waInstance71072772999/getWaSettings/*' => Http::response([
                'stateInstance' => 'authorized',
                'phone' => '972505000111',
            ]),
        ]);
        [$user, $bot] = $this->bots();
        app(MansourLines::class)->sync($bot, [$old], null, ['71072772143']);
        app(MowahadaLinePool::class)->remember([$old]);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $bot->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501234567@c.us',
            'metadata' => ['mansour_line' => '71072772143'],
        ]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.update', '71072772143'), [
                'bot' => $bot->id,
                'id' => '71072772143',
                'token' => $token,
            ])
            ->assertRedirect();

        $row = MowahadaLine::query()->where('wa_instance', '71072772143')->firstOrFail();
        $this->assertSame(
            'https://7107.api.greenapi.com/waInstance71072772143/sendMessage/'.$token,
            $row->url,
        );
        $this->assertSame('972505183718', $row->phone);
        $stored = app(MansourLines::class)->lines($bot->fresh());
        $this->assertSame($row->url, $stored[0]['url']);
        $this->assertTrue(app(MansourLines::class)->isOn($stored[0]));

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.update', '71072772143'), [
                'bot' => $bot->id,
                'id' => '71072772999',
                'token' => $token,
            ])
            ->assertRedirect();

        $this->assertNull(MowahadaLine::query()->where('wa_instance', '71072772143')->first());
        $moved = MowahadaLine::query()->where('wa_instance', '71072772999')->firstOrFail();
        $this->assertSame('972505000111', $moved->phone);
        $this->assertSame(['71072772999'], array_column(app(MansourLines::class)->lines($bot->fresh()), 'id'));
        $this->assertSame('71072772999', $conversation->fresh()->metadata['mansour_line']);

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('تسخين', false)
            ->assertSee('تعديل وتحديث', false)
            ->assertSee('رسالة التسخين', false);
    }

    public function test_a_one_day_suspension_shows_on_the_pool_and_stays_out_of_warm_up(): void
    {
        $until = now()->addHours(23)->timestamp;
        Http::fake([
            '*/waInstance111/getWaSettings/*' => Http::response([
                'stateInstance' => 'suspended',
                'suspendedUntil' => $until,
                'phone' => '972501111111',
            ]),
        ]);
        [$user, $bot] = $this->bots();
        app(MansourLines::class)->sync($bot, [self::LINE_A]);
        app(MowahadaLinePool::class)->remember([self::LINE_A]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.check'), ['bot' => $bot->id])
            ->assertRedirect();

        $row = MowahadaLine::query()->where('wa_instance', '111')->firstOrFail();
        $this->assertSame('suspended', $row->reason);
        $this->assertSame($until, (int) $row->suspended_until);

        $when = \Illuminate\Support\Carbon::createFromTimestamp($until, 'Asia/Jerusalem')->format('d/m H:i');
        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('موقوف ليوم · حتى '.$when, false)
            ->assertSee('حالة الإيقاف تتحدث كل دقيقة', false);

        Http::fake();
        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.warm'), [
                'bot' => $bot->id,
                'lines' => ['111'],
                'phones' => '972501234567',
                'message' => 'مرحبا',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'التسخين: نجح 0، فشل 0. تُرك الموقوف: 1.');
        Http::assertNothingSent();
    }

    public function test_status_poll_refreshes_a_suspension_and_clears_it_when_the_line_returns(): void
    {
        $until = now()->addDay()->timestamp;
        $calls = 0;
        Http::fake(function () use (&$calls, $until) {
            $calls++;

            return Http::response($calls === 1 ? [
                'stateInstance' => 'suspended',
                'suspendedUntil' => $until,
                'phone' => '972501111111',
            ] : [
                'stateInstance' => 'authorized',
                'phone' => '972501111111',
            ]);
        });
        [$user, $bot] = $this->bots();
        app(MowahadaLinePool::class)->remember([self::LINE_A]);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.farz.numbers.status', ['bot' => $bot->id]))
            ->assertOk()
            ->assertJsonPath('summary', 'موقوف ليوم: 1')
            ->assertJsonPath('lines.0.id', '111')
            ->assertJsonPath('lines.0.blocked', true);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.farz.numbers.status', ['bot' => $bot->id]))
            ->assertOk()
            ->assertJsonPath('summary', 'لا يوجد إيقاف ليوم')
            ->assertJsonPath('lines.0.badge', 'في المصدر')
            ->assertJsonPath('lines.0.blocked', false);

        $row = MowahadaLine::query()->where('wa_instance', '111')->firstOrFail();
        $this->assertNull($row->suspended_until);
        $this->assertSame(MansourLines::READY, $row->status);
    }

    public function test_check_updates_the_catalog_and_the_bot_using_it(): void
    {
        Http::fake([
            '*/waInstance111/getWaSettings/*' => Http::response(['stateInstance' => 'authorized', 'phone' => '972501111111']),
        ]);
        [$user, $bot] = $this->bots();
        app(MansourLines::class)->sync($bot, [self::LINE_A]);
        app(MowahadaLinePool::class)->remember([self::LINE_A]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.farz.numbers.check'), ['bot' => $bot->id])
            ->assertRedirect();

        $row = MowahadaLine::query()->where('wa_instance', '111')->firstOrFail();
        $this->assertSame('972501111111', $row->phone);
        $this->assertSame(MansourLines::READY, $row->status);
        $this->assertSame('972501111111', app(MansourLines::class)->lines($bot->fresh())[0]['phone']);
    }

    public function test_settings_refresh_replaces_a_changed_whatsapp_number(): void
    {
        Http::fake([
            '*/waInstance111/getWaSettings/*' => Http::response([
                'stateInstance' => 'authorized',
                'phone' => '972509998887',
            ]),
            '*/waInstance222/getWaSettings/*' => Http::response([
                'stateInstance' => 'yellowCard',
                'phone' => '972508887776',
            ]),
            '*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized']),
        ]);
        [$user, $bot] = $this->bots();
        $lines = app(MansourLines::class);
        $pool = app(MowahadaLinePool::class);
        $lines->sync($bot, [self::LINE_A]);
        $pool->remember([self::LINE_A, self::LINE_B]);
        MowahadaLine::query()->where('wa_instance', '111')->update(['phone' => '972501111111']);
        MowahadaLine::query()->where('wa_instance', '222')->update(['phone' => '972502222222']);
        $lines->rememberPhone($bot->fresh(), '111', '972501111111');

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.lines.check', $bot))
            ->assertRedirect();

        $this->assertSame('972509998887', MowahadaLine::query()->where('wa_instance', '111')->firstOrFail()->phone);
        $catalog = MowahadaLine::query()->where('wa_instance', '222')->firstOrFail();
        $this->assertSame('972508887776', $catalog->phone);
        $this->assertSame('yellowCard', $catalog->reason);
        $this->assertSame('972509998887', $lines->lines($bot->fresh())[0]['phone']);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('+972509998887', false)
            ->assertSee('+972508887776', false)
            ->assertSee('فحص الحالة', false);

        $this->actingAs($user)
            ->get(route('ai-chatbot.farz.numbers', ['bot' => $bot->id]))
            ->assertOk()
            ->assertSee('فحص الحالة', false)
            ->assertSee('data-pool-phone', false)
            ->assertSee('+972509998887', false)
            ->assertSee('+972508887776', false);
    }

    public function test_selected_pool_lines_turn_on_and_stay_grouped(): void
    {
        [$user, $bot] = $this->bots();
        $other = app(MansourBotService::class)->copy($bot, $user);
        $other->forceFill(['name' => 'ديما'])->save();
        $lines = app(MansourLines::class);
        $pool = app(MowahadaLinePool::class);
        $lines->sync($other, [self::LINE_B], null, ['222']);
        $pool->remember([self::LINE_A, self::LINE_B]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('غير مربوطة')
            ->assertSee('على بوت آخر')
            ->assertSee('ديما')
            ->assertSee('data-num-pick', false);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.active', $bot), [
                'line_ids' => ['111'],
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ids', ['111']);

        $rows = $lines->lines($bot->fresh());
        $this->assertSame(['111'], array_column($rows, 'id'));
        $this->assertTrue($lines->isOn($rows[0]));

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.active', $bot), [
                'line_ids' => ['222'],
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ids', ['222']);

        $rows = $lines->lines($bot->fresh());
        $this->assertEqualsCanonicalizing(['111', '222'], array_column($rows, 'id'));
        $this->assertSame([], $lines->lines($other->fresh()));
    }

    public function test_numbers_move_between_bots_and_never_sit_on_two(): void
    {
        [$user, $bot] = $this->bots();
        $other = app(MansourBotService::class)->copy($bot, $user);
        $lines = app(MansourLines::class);
        app(MowahadaLinePool::class)->remember([self::LINE_A, self::LINE_B]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.assign', $bot), ['line_ids' => ['111', '222'], 'bot_id' => $bot->id])
            ->assertOk()
            ->assertJsonPath('ids', ['111', '222']);
        $this->assertSame(['111', '222'], array_column($lines->lines($bot->fresh()), 'id'));
        $this->assertTrue($lines->isOn($lines->lines($bot->fresh())[0]));

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.assign', $bot), ['line_ids' => ['222'], 'bot_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('ids', ['222']);
        $this->assertSame(['111'], array_column($lines->lines($bot->fresh()), 'id'));
        $this->assertSame(['222'], array_column($lines->lines($other->fresh()), 'id'));

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.farz.numbers.assign', ['line' => '111', 'bot' => $bot->id]), ['target' => $other->id])
            ->assertOk()
            ->assertJsonPath('bot_id', $other->id);
        $this->assertSame([], $lines->lines($bot->fresh()));
        $this->assertEqualsCanonicalizing(['111', '222'], array_column($lines->lines($other->fresh()), 'id'));

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.farz.numbers.assign', ['line' => '111', 'bot' => $bot->id]), ['target' => 0])
            ->assertOk()
            ->assertJsonPath('bot_id', 0);
        $this->assertSame(['222'], array_column($lines->lines($other->fresh()), 'id'));
        $this->assertNotNull(MowahadaLine::query()->where('wa_instance', '111')->first());
    }

    public function test_saving_the_bot_form_leaves_its_numbers_alone(): void
    {
        [$user, $bot] = $this->bots();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B]);
        $lines->setActive($bot->fresh(), '222', false);

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), [
                'name' => 'اسم جديد',
                'lines_keep' => '1',
                'system_prompt' => 'اسأل بهدوء.',
                'opening_message' => 'يسعد اوقاتك',
            ])
            ->assertRedirect();

        $stored = $lines->lines($bot->fresh());
        $this->assertSame('اسم جديد', $bot->fresh()->name);
        $this->assertSame(['111', '222'], array_column($stored, 'id'));
        $this->assertTrue($lines->isOn($stored[0]));
        $this->assertFalse($lines->isOn($stored[1]));
    }

    public function test_status_check_asks_every_line_at_once_and_answers_json(): void
    {
        Http::fake([
            '*/getWaSettings/*' => Http::response(['stateInstance' => 'authorized', 'phone' => '972501234567']),
            '*/getContactInfo/*' => Http::response(['name' => 'خط أ']),
            '*' => Http::response([], 200),
        ]);
        [$user, $bot] = $this->bots();
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A, self::LINE_B], null, ['111', '222']);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.lines.check', $bot))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checked', 2);

        $stored = $lines->lines($bot->fresh());
        $this->assertSame('972501234567', $stored[0]['phone']);
        $this->assertSame('خط أ', $stored[1]['name']);
    }

    public function test_dedupe_keeps_a_number_only_where_it_is_on(): void
    {
        [$user, $bot] = $this->bots();
        $other = app(MansourBotService::class)->copy($bot, $user);
        $lines = app(MansourLines::class);
        $lines->sync($bot, [self::LINE_A], null, ['111']);
        $settings = $other->fresh()->integration_settings;
        $settings['greenapi_lines'] = [['url' => self::LINE_A, 'status' => MansourLines::READY, 'active' => false]];
        $other->forceFill(['integration_settings' => $settings])->save();

        $this->assertSame(1, $lines->dedupe());
        $this->assertSame(['111'], array_column($lines->lines($bot->fresh()), 'id'));
        $this->assertSame([], $lines->lines($other->fresh()));
    }

    /**
     * @return array{0: User, 1: ChatbotInstance}
     */
    private function bots(): array
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', User::MOWAHADA_EMAIL)->firstOrFail();

        return [$user, ChatbotInstance::query()->where('user_id', $user->id)->where('integration_type', 'mansour')->firstOrFail()];
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $active
     * @return array<string, mixed>
     */
    private function form(ChatbotInstance $bot, array $ids, array $active): array
    {
        return [
            'name' => $bot->name,
            'pool_present' => '1',
            'pool_ids' => $ids,
            'greenapi_active_present' => '1',
            'greenapi_active' => $active,
            'system_prompt' => 'اسأل بهدوء.',
            'opening_message' => 'يسعد اوقاتك',
        ];
    }
}
