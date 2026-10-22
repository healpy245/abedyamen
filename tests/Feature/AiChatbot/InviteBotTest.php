<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\User;
use App\Services\AiChatbot\InviteInsightService;
use App\Services\AiChatbot\InvitePrompt;
use App\Services\AiChatbot\MansourBotService;
use App\Services\AiChatbot\MansourLines;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InviteBotTest extends TestCase
{
    use RefreshDatabase;

    private const LINE_A = 'https://7103.api.greenapi.com/waInstance111/sendMessage/tokA';

    public function test_invite_bot_is_created_once_with_the_conference_message(): void
    {
        $user = $this->owner();
        $invite = app(MansourBotService::class)->ensureInviteBot($user);

        $this->assertInstanceOf(ChatbotInstance::class, $invite);
        $this->assertTrue($invite->isInviteBot());
        $this->assertTrue($invite->isWomenInviteBot());
        $this->assertSame('دعوه نسائيه', $invite->name);
        $this->assertSame($invite->id, app(MansourBotService::class)->ensureInviteBot($user)?->id);

        $this->assertSame('مرحبا فاطمة، يعطيكي العافيه', InvitePrompt::greetingFor('فاطمة محمد علي'));
        $this->assertStringNotContainsString('المؤتمر', InvitePrompt::greetingFor('فاطمة محمد علي'));

        $opening = InvitePrompt::openingFor('فاطمة محمد علي');
        $this->assertStringStartsWith('يسعدنا أن ندعوكِ', $opening);
        $this->assertStringNotContainsString('أهلًا وسهلًا', $opening);
        $this->assertStringNotContainsString('فاطمة', $opening);
        $this->assertStringContainsString('المؤتمر النسائي', $opening);
        $this->assertStringContainsString('0502018556', $opening);
        $this->assertStringNotContainsString('لإلغاء الاشتراك', $opening);
        $this->assertStringNotContainsString('أو «إلغاء»', $opening);
        $this->assertStringEndsWith(InvitePrompt::ASK, $opening);
        $this->assertSame(InvitePrompt::BODY, InvitePrompt::openingFor(null));
        $legacy = InvitePrompt::openingFor('فاطمة محمد علي', "أهلًا وسهلًا أختي {name}\n".InvitePrompt::BODY);
        $this->assertStringNotContainsString('أهلًا وسهلًا', $legacy);
        $this->assertStringNotContainsString('فاطمة', $legacy);
    }

    public function test_given_name_is_the_person_only_and_arabic(): void
    {
        $this->assertSame('شيرين', InvitePrompt::givenName('שירין כהן'));
        $this->assertSame('عبد الرحمن', InvitePrompt::givenName('عبد الرحمن سواعد'));
        $this->assertSame('عبد الحكيم', InvitePrompt::givenName('عبد الحكيم أمارة'));
        $this->assertSame('عز الدين', InvitePrompt::givenName('عز الدين بصول'));
        $this->assertSame('حليمة', InvitePrompt::givenName('حليمة أبو دعابس- ام انس'));
        $this->assertSame('رفيقة', InvitePrompt::givenName('رفيقة مدلج (أم بلال )'));
        $this->assertSame('عمر', InvitePrompt::givenName('الشيخ د. عمر كيال'));
        $this->assertSame('نضال', InvitePrompt::givenName('ابو نضال يوسف طربيه'));
        $this->assertSame('سحر', InvitePrompt::givenName('סהראב אבו ניאת'));
        $this->assertSame('أميرة', InvitePrompt::givenName('1. أميرة محمد أمارة'));
        $this->assertSame('عايدة', InvitePrompt::givenName('ام معتصم عايدة شيني'));
        $this->assertSame('', InvitePrompt::givenName('ام محمد'));
    }

    public function test_replies_split_into_positive_cancel_and_no_reply(): void
    {
        $user = $this->owner();
        $invite = app(MansourBotService::class)->ensureInviteBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $invite);

        $yes = $this->chat($invite, 'فاطمة', '972501111111', 'تمام رح أجي');
        $stop = $this->chat($invite, 'سارة', '972502222222', 'إلغاء');
        $quiet = $this->chat($invite, 'هدى', '972503333333', null);

        $this->assertSame(InvitePrompt::POSITIVE, InvitePrompt::read($yes->fresh())['stance']);
        $this->assertSame(InvitePrompt::CANCEL, InvitePrompt::read($stop->fresh())['stance']);

        $summary = app(InviteInsightService::class)->summarize($invite);
        $this->assertSame(1, $summary['positive']);
        $this->assertSame(1, $summary['cancel']);
        $this->assertSame(1, $summary['no_reply']);
        $this->assertSame([$yes->id], $summary['conversation_ids']['positive']);
        $this->assertSame([$quiet->id], $summary['conversation_ids']['no_reply']);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $invite))
            ->assertOk()
            ->assertSee('رد إيجابي')
            ->assertSee('إلغاء')
            ->assertSee('لم ترد');
    }

    public function test_a_number_lives_on_one_bot_and_moves_when_another_takes_it(): void
    {
        $user = $this->owner();
        $farz = ChatbotInstance::query()->where('user_id', $user->id)->where('integration_type', 'mansour')->firstOrFail();
        $invite = app(MansourBotService::class)->ensureInviteBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $invite);
        $lines = app(MansourLines::class);

        $lines->sync($farz, [self::LINE_A], null, ['111']);
        $lines->sync($invite, [self::LINE_A], null, ['111']);

        $this->assertTrue($lines->isOn($lines->lines($invite->fresh())[0]));
        $this->assertSame([], $lines->lines($farz->fresh()));

        $this->assertSame(['111'], $lines->assign($farz->fresh(), ['111'], $farz->fresh()));
        $this->assertTrue($lines->isOn($lines->lines($farz->fresh())[0]));
        $this->assertSame([], $lines->lines($invite->fresh()));

        $this->assertTrue($lines->setActive($farz->fresh(), '111', false));
        $this->assertFalse($lines->isOn($lines->lines($farz->fresh())[0]));
        $this->assertSame([], $lines->lines($invite->fresh()));

        $this->assertSame(['111'], $lines->assign($farz->fresh(), ['111'], null));
        $this->assertSame([], $lines->lines($farz->fresh()));
    }

    public function test_trigger_sends_a_greeting_and_the_poster_follows_the_reply(): void
    {
        $user = $this->owner();
        $invite = app(MansourBotService::class)->ensureInviteBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $invite);
        $lines = app(MansourLines::class);
        $lines->sync($invite, [self::LINE_A], null, ['111']);

        $contact = app(MansourBotService::class)->addContact($invite, '0501112233', 'فاطمة محمد علي');
        $this->assertNotNull($contact);

        $greeting = InvitePrompt::greetingFor('فاطمة محمد علي');
        $invitation = InvitePrompt::openingFor('فاطمة محمد علي');
        $this->assertLessThanOrEqual(1024, mb_strlen($invitation));
        $this->assertNotNull(InvitePrompt::posterPath());

        Http::fake([
            'https://7103.api.greenapi.com/*' => Http::response(['idMessage' => 'poster-1'], 200),
        ]);

        $result = app(MansourBotService::class)->trigger($invite->fresh(), [(int) $contact->id], null, '111');

        $this->assertSame(1, $result['sent']);
        Http::assertSent(function ($request) use ($greeting): bool {
            return str_contains($request->url(), '/sendMessage/')
                && ($request->data()['message'] ?? '') === $greeting;
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/sendFileByUpload/'));

        $message = ChatbotMessage::query()->where('role', 'assistant')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertFalse($message->isImageAttachment());
        $this->assertSame($greeting, $message->message);

        $conversation = ChatbotConversation::query()->where('instance_id', $invite->id)->firstOrFail();
        $this->assertTrue(app(MansourBotService::class)->deliverInviteInvitation($invite->fresh(), $conversation));
        Http::assertSent(function ($request) use ($invitation): bool {
            return str_contains($request->url(), '/sendFileByUpload/')
                && str_contains((string) $request->body(), $invitation)
                && str_contains((string) $request->body(), 'womens-conference.jpg');
        });

        $poster = ChatbotMessage::query()->where('message_type', 'image')->latest('id')->first();
        $this->assertNotNull($poster);
        $this->assertTrue($poster->isImageAttachment());
        $this->assertSame($invitation, $poster->message);
        $this->assertSame('invite/womens-conference.jpg', $poster->attachment_path);
        $this->assertFalse(app(MansourBotService::class)->deliverInviteInvitation($invite->fresh(), $conversation->fresh()));
    }

    public function test_confirmation_and_cancellation_words_close_the_chat(): void
    {
        $opening = ['role' => 'assistant', 'message' => InvitePrompt::openingFor('فاطمة')];
        $prior = ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false];

        $yes = InvitePrompt::nextReply([$opening, ['role' => 'user', 'message' => 'تأكيد']], 'فاطمة', $prior);
        $this->assertSame(InvitePrompt::POSITIVE, $yes['stance']);
        $this->assertTrue($yes['close']);

        $no = InvitePrompt::nextReply([$opening, ['role' => 'user', 'message' => 'إلغاء']], 'فاطمة', $prior);
        $this->assertSame(InvitePrompt::CANCEL, $no['stance']);
        $this->assertTrue($no['close']);
    }

    public function test_a_quiet_contact_is_nudged_once_after_ten_minutes(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        try {
            $user = $this->owner();
            $invite = app(MansourBotService::class)->ensureInviteBot($user);
            $this->assertInstanceOf(ChatbotInstance::class, $invite);
            app(MansourLines::class)->sync($invite, [self::LINE_A], null, ['111']);
            $contact = app(MansourBotService::class)->addContact($invite, '0501112233', 'فاطمة محمد علي');
            $this->assertNotNull($contact);

            Http::fake([
                'https://7103.api.greenapi.com/*' => Http::response(['idMessage' => 'nudge-1'], 200),
            ]);
            app(MansourBotService::class)->trigger($invite->fresh(), [(int) $contact->id], null, '111');
            $conversation = ChatbotConversation::query()->where('instance_id', $invite->id)->firstOrFail();

            $this->assertFalse(app(MansourBotService::class)->sendInviteNudge((int) $conversation->id, (int) $contact->id));
            Http::assertNotSent(fn ($request): bool => str_contains((string) ($request->data()['message'] ?? ''), '🌸'));

            Carbon::setTestNow('2026-10-20 12:10:00');
            $this->assertTrue(app(MansourBotService::class)->sendInviteNudge((int) $conversation->id, (int) $contact->id));
            $nudge = (string) ChatbotMessage::query()->where('conversation_id', $conversation->id)->where('message_type', 'text')->orderByDesc('id')->value('message');
            $this->assertStringContainsString('يعطيكي العافيه فاطمة', $nudge);
            $this->assertStringContainsString('🌸', $nudge);
            $this->assertStringNotContainsString('محمد', $nudge);
            $this->assertFalse(app(MansourBotService::class)->sendInviteNudge((int) $conversation->id, (int) $contact->id));

            $replied = app(MansourBotService::class)->addContact($invite, '0501112244', 'سارة علي');
            $this->assertNotNull($replied);
            Carbon::setTestNow('2026-10-20 12:20:00');
            app(MansourBotService::class)->trigger($invite->fresh(), [(int) $replied->id], null, '111');
            $second = ChatbotConversation::query()->where('contact_phone', '972501112244')->firstOrFail();
            ChatbotMessage::query()->create([
                'conversation_id' => $second->id,
                'role' => 'user',
                'message' => 'تمام',
            ]);
            $second->forceFill(['last_customer_message_at' => now()])->save();
            Carbon::setTestNow('2026-10-20 12:31:00');
            $this->assertFalse(app(MansourBotService::class)->sendInviteNudge((int) $second->id, (int) $replied->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unclear_reply_asks_whether_she_is_coming(): void
    {
        $opening = ['role' => 'assistant', 'message' => InvitePrompt::openingFor('Test')];

        $hello = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'هلا'],
        ], 'Test', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);

        $this->assertSame(InvitePrompt::CLARIFY, $hello['text']);
        $this->assertSame(InvitePrompt::OPEN, $hello['stance']);
        $this->assertFalse($hello['close']);
        $this->assertStringNotContainsString('مساعدتك', $hello['text']);

        $split = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'هلا'],
            ['role' => 'user', 'message' => 'رح أجي'],
        ], 'Test', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);

        $this->assertSame(InvitePrompt::POSITIVE, $split['stance']);
        $this->assertTrue($split['close']);

        $no = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'مش رح أجي'],
        ], 'Test', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);
        $this->assertSame(InvitePrompt::PLEA, $no['text']);
        $this->assertSame(InvitePrompt::OPEN, $no['stance']);
        $this->assertFalse($no['close']);
        $this->assertTrue($no['plea'] ?? false);

        $busy = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'ولله مني فاضيه'],
        ], 'آية', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);
        $this->assertSame(InvitePrompt::PLEA, $busy['text']);
        $this->assertFalse($busy['close']);

        $final = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'ولله مني فاضيه'],
            ['role' => 'assistant', 'message' => InvitePrompt::PLEA],
            ['role' => 'user', 'message' => 'لا ما في مجال'],
        ], 'آية', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false, 'plea' => true]);
        $this->assertSame(InvitePrompt::CANCEL, $final['stance']);
        $this->assertTrue($final['close']);
        $this->assertStringContainsString('شلنا اسمك من القائمة', $final['text']);
        $this->assertStringNotContainsString('اسمك انشال', $final['text']);

        $again = InvitePrompt::nextReply([
            $opening,
            ['role' => 'assistant', 'message' => InvitePrompt::PLEA],
            ['role' => 'user', 'message' => 'لا صعب صعب كثير'],
        ], 'آية', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);
        $this->assertSame(InvitePrompt::CANCEL, $again['stance']);
        $this->assertTrue($again['close']);
        $this->assertStringNotContainsString('ما في مجال بتاتا', $again['text']);

        $youthPlea = InvitePrompt::nextReply([
            ['role' => 'user', 'message' => 'صعب ولله'],
        ], 'يامن', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false], InvitePrompt::YOUTH);
        $this->assertSame(InvitePrompt::pleaFor(InvitePrompt::YOUTH), $youthPlea['text']);
        $this->assertStringContainsString('forms.gle/mgzREfC9RWnna7TX7', $youthPlea['text']);
        $this->assertStringEndsWith(InvitePrompt::YOUTH_AFTER_FILE, $youthPlea['text']);
        $this->assertTrue($youthPlea['plea'] ?? false);

        $youthAgain = InvitePrompt::nextReply([
            ['role' => 'assistant', 'message' => $youthPlea['text']],
            ['role' => 'user', 'message' => 'لا صعب صعب كثير'],
        ], 'يامن', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false], InvitePrompt::YOUTH);
        $this->assertSame(InvitePrompt::CANCEL, $youthAgain['stance']);
        $this->assertStringNotContainsString('ما في مجال بتاتا', $youthAgain['text']);

        $done = InvitePrompt::nextReply([
            ['role' => 'assistant', 'message' => $youthPlea['text']],
            ['role' => 'user', 'message' => 'تم'],
        ], 'يامن', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false], InvitePrompt::YOUTH);
        $this->assertSame(InvitePrompt::POSITIVE, $done['stance']);
        $this->assertTrue($done['close']);

        $grief = InvitePrompt::nextReply([
            $opening,
            ['role' => 'user', 'message' => 'في حالة وفاة ما بقدر أجي'],
        ], 'آية', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);
        $this->assertSame(InvitePrompt::CANCEL, $grief['stance']);
        $this->assertTrue($grief['close']);
        $this->assertStringContainsString('متاسفه على الازعاج', $grief['text']);
        $this->assertStringContainsString('🌸', $grief['text']);
        $this->assertNotSame(InvitePrompt::PLEA, $grief['text']);

        InvitePrompt::$testResponder = static fn (): string => json_encode([
            'stance' => 'open',
            'text' => 'مرحبًا Test، كيف يمكنني مساعدتكِ اليوم؟',
            'note' => '',
            'close' => false,
        ], JSON_UNESCAPED_UNICODE);
        try {
            $generic = InvitePrompt::nextReply([
                $opening,
                ['role' => 'user', 'message' => 'بفكر بالموضوع'],
            ], 'Test', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false]);
        } finally {
            InvitePrompt::$testResponder = null;
        }

        $this->assertSame(InvitePrompt::CLARIFY, $generic['text']);
        $this->assertFalse($generic['close']);
    }

    public function test_youth_invite_bot_greets_then_sends_its_own_poster(): void
    {
        $user = $this->owner();
        $youth = app(MansourBotService::class)->ensureYouthInviteBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $youth);
        $this->assertTrue($youth->isYouthInviteBot());
        $this->assertTrue($youth->isInviteBot());
        $this->assertFalse($youth->isWomenInviteBot());
        $this->assertSame('دعوة الشباب', $youth->name);
        $this->assertSame($youth->id, app(MansourBotService::class)->ensureYouthInviteBot($user)?->id);
        $women = app(MansourBotService::class)->ensureInviteBot($user);
        $this->assertNotSame($youth->id, $women?->id);

        $greeting = InvitePrompt::greetingFor('أحمد علي', InvitePrompt::YOUTH);
        $this->assertSame('مرحبا أحمد، يعطيك العافيه', $greeting);
        $invitation = InvitePrompt::invitationFor('أحمد علي', null, InvitePrompt::YOUTH);
        $this->assertStringContainsString('ندعو شبابنا', $invitation);
        $this->assertStringContainsString('بس تسجل بالرابط ابعتلي <<تم>>', $invitation);
        $this->assertStringContainsString('الساعة 14:00', $invitation);
        $this->assertStringContainsString('https://forms.gle/mgzREfC9RWnna7TX7', $invitation);
        $this->assertStringNotContainsString('المؤتمر النسائي', $invitation);
        $this->assertLessThanOrEqual(1024, mb_strlen($invitation));
        $this->assertNotNull(InvitePrompt::posterPath(InvitePrompt::YOUTH));

        $yes = InvitePrompt::nextReply([
            ['role' => 'assistant', 'message' => $invitation],
            ['role' => 'user', 'message' => 'تأكيد'],
        ], 'أحمد', ['stance' => InvitePrompt::OPEN, 'note' => '', 'close' => false, 'sent' => true], InvitePrompt::YOUTH);
        $this->assertSame(InvitePrompt::POSITIVE, $yes['stance']);
        $this->assertStringNotContainsString('منستناكِ', $yes['text']);

        app(MansourLines::class)->sync($youth, [self::LINE_A], null, ['111']);
        $contact = app(MansourBotService::class)->addContact($youth, '0501112255', 'أحمد علي');
        $this->assertNotNull($contact);

        Http::fake([
            'https://7103.api.greenapi.com/*' => Http::response(['idMessage' => 'youth-1'], 200),
        ]);
        $result = app(MansourBotService::class)->trigger($youth->fresh(), [(int) $contact->id], null, '111');
        $this->assertSame(1, $result['sent']);
        Http::assertSent(function ($request) use ($greeting): bool {
            return str_contains($request->url(), '/sendMessage/')
                && ($request->data()['message'] ?? '') === $greeting;
        });

        $conversation = ChatbotConversation::query()->where('instance_id', $youth->id)->firstOrFail();
        $this->assertTrue(app(MansourBotService::class)->deliverInviteInvitation($youth->fresh(), $conversation));
        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), '/sendFileByUpload/')
                && str_contains($body, 'youth-conference.jpg')
                && str_contains($body, 'forms.gle/mgzREfC9RWnna7TX7');
        });
        $poster = ChatbotMessage::query()->where('conversation_id', $conversation->id)->where('message_type', 'image')->first();
        $this->assertNotNull($poster);
        $this->assertSame('invite/youth-conference.jpg', $poster->attachment_path);
    }

    private function owner(): User
    {
        $this->seed(WorkspaceUserSeeder::class);

        return User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
    }

    private function chat(ChatbotInstance $bot, string $name, string $phone, ?string $reply): ChatbotConversation
    {
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $bot->user_id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $phone.'@c.us',
            'contact_name' => $name,
            'contact_phone' => $phone,
            'title' => $name,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => InvitePrompt::openingFor($name),
        ]);
        if ($reply === null) {
            return $conversation;
        }

        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message' => $reply,
        ]);
        $decision = InvitePrompt::nextReply([
            ['role' => 'assistant', 'message' => InvitePrompt::openingFor($name)],
            ['role' => 'user', 'message' => $reply],
        ], $name, InvitePrompt::read($conversation));
        InvitePrompt::remember($conversation, $decision['stance'], $decision['note'], $decision['close'], (bool) ($decision['plea'] ?? false));

        return $conversation;
    }
}
