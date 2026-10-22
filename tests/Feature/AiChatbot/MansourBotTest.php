<?php

namespace Tests\Feature\AiChatbot;

use App\Enums\Project;
use App\Jobs\ProcessMansourConversationJob;
use App\Services\AiChatbot\AiChatbotService;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\MansourConversationBurstService;
use App\Services\AiChatbot\MansourHumanDelay;
use App\Services\AiChatbot\MansourPrompt;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use App\Models\AiChatbot\MansourContact;
use App\Models\AiChatbot\MansourSendRun;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MansourBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_user_only_sees_mansour_and_stays_in_arabic(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();

        $this->assertSame(['ai-chatbot'], $user->projectKeys());
        $this->assertFalse($user->is_admin);

        $bots = ChatbotInstance::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $bots);
        $this->assertSame('بوت الفرز', $bots->first()->name);
        $this->assertTrue($bots->first()->hasMansourIntegration());

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('ai-chatbot.index'));

        $this->actingAs($user)
            ->get(route('form.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get(route('ai-chatbot.workspace.conversations', $bots->first()))
            ->assertOk()
            ->assertSee('بوت الفرز')
            ->assertSee('الموحدة')
            ->assertSee('إرسال')
            ->assertSee('حذف المحددين')
            ->assertSee('id="mansour-send-error"', false)
            ->assertSee('id="mansour-send"', false)
            ->assertSee('id="mansour-safe-sort"', false)
            ->assertSee('ترتيب آمن')
            ->assertSee('id="mansour-live-stop"', false)
            ->assertSee('id="mansour-live-resume"', false)
            ->assertSee('إيقاف')
            ->assertSee('متابعة')
            ->assertSee('تجربة الشات بوت');

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.test.page', $bots->first()))
            ->assertOk()
            ->assertSee('تجربة الشات بوت')
            ->assertSee('id="test-input"', false);
    }

    public function test_settings_are_only_the_basic_fields(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $bot))
            ->assertOk()
            ->assertSee('id="farz-nums"', false)
            ->assertSee('id="system_prompt"', false)
            ->assertSee('id="opening_message"', false)
            ->assertDontSee('pos-demo')
            ->assertDontSee('clear-conversations');

        $this->actingAs($user)
            ->put(route('ai-chatbot.workspace.mansour.settings.update', $bot), [
                'name' => 'منصور',
                'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
                'system_prompt' => 'اسأل عن التصويت بهدوء.',
                'opening_message' => 'مرحبا، معك منصور.',
            ])
            ->assertRedirect(route('ai-chatbot.workspace.settings', $bot));

        $bot->refresh();
        $this->assertSame('اسأل عن التصويت بهدوء.', $bot->system_prompt);
        $this->assertSame('مرحبا، معك منصور.', $bot->integration_settings['opening_message']);
    }

    public function test_analytics_split_replies_into_four_groups(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $this->chat($bot, 'a', 'بصوت لراعم');
        $this->chat($bot, 'b', 'بفكر بالموضوع');
        $this->chat($bot, 'c', 'ما بصوت');
        $this->chat($bot, 'd', 'بصوت لليكود');
        $this->chat($bot, 'e', 'אצביע לרע"ם');
        $this->chat($bot, 'f', 'أكيد بصوت');
        $this->chat($bot, 'g', 'يا هي يا إما المشترك');

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.analytics', $bot))
            ->assertOk()
            ->assertJsonPath('analytics.certain', 2)
            ->assertJsonPath('analytics.unsure', 1)
            ->assertJsonPath('analytics.not_voting', 1)
            ->assertJsonPath('analytics.other_side', 1)
            ->assertJsonPath('analytics.certain_pct', 40)
            ->assertJsonPath('analytics.no_reply', 0);
    }

    public function test_a_no_after_the_vote_question_is_a_boycott_and_silence_is_no_reply(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $ghazi = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972509998877@c.us',
            'contact_name' => 'غازي عيسى',
            'contact_phone' => '972509998877',
            'title' => 'غازي عيسى',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $ghazi->id,
            'role' => 'assistant',
            'message' => 'معك ديما، كيف الاحوال غازي؟ متاسفة إذا رسالتي إجت فجأة، احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا. في مجال أسألك سؤال صغير؟',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $ghazi->id,
            'role' => 'user',
            'message' => 'لا',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $ghazi->id,
            'role' => 'assistant',
            'message' => 'بالنسبة لانتخابات 27.10، شو وضعك مع التصويت هالمرة؟ رح تصوّت ولا كيف؟',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $ghazi->id,
            'role' => 'user',
            'message' => 'لا',
        ]);

        $silent = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972501110000@c.us',
            'contact_name' => 'سامر',
            'contact_phone' => '972501110000',
            'title' => 'سامر',
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $silent->id,
            'role' => 'assistant',
            'message' => 'يسعد اوقاتك اخ سامر شو الاخبار',
        ]);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.analytics', $bot))
            ->assertOk()
            ->assertJsonPath('analytics.not_voting', 1)
            ->assertJsonPath('analytics.no_reply', 1)
            ->assertJsonPath('analytics.conversation_ids.not_voting.0', $ghazi->id)
            ->assertJsonPath('analytics.conversation_ids.no_reply.0', $silent->id);

        $boycott = $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get(route('ai-chatbot.workspace.analytics.export', [$bot, 'not_voting']));
        $boycott->assertOk();
        $boycottBody = $boycott->streamedContent();
        $this->assertStringContainsString('972509998877', $boycottBody);
        $this->assertStringContainsString('لا يريد التصويت', $boycottBody);
        $this->assertStringContainsString('الاسم', $boycottBody);
        $this->assertStringContainsString('المجموعة', $boycottBody);
        $this->assertStringContainsString('customWidth="1"', $boycottBody);
        $this->assertStringContainsString('width="88"', $boycottBody);
        $disposition = rawurldecode((string) $boycott->headers->get('content-disposition'));
        $this->assertStringContainsString('بوت الفرز - لا يريد التصويت', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);

        $quiet = $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.analytics.export', [$bot, 'no_reply']));
        $quiet->assertOk();
        $this->assertStringContainsString('972501110000', $quiet->streamedContent());
        $this->assertStringContainsString('ولم يرد', $quiet->streamedContent());
    }

    public function test_a_brush_off_stays_refused_unless_the_chat_ends_on_a_vote(): void
    {
        $this->assertSame('refused', MansourPrompt::dialogueResult([
            ['role' => 'assistant', 'message' => 'شو وضعك مع التصويت هالمرة؟'],
            ['role' => 'user', 'message' => 'شو دخلك'],
        ])['group']);
        $this->assertSame('refused', MansourPrompt::dialogueResult([
            ['role' => 'assistant', 'message' => 'فتحي بالنسبة للانتخابات هالمرة شو وضعك؟ ناوي تصوّت ولا كيف؟'],
            ['role' => 'user', 'message' => 'خلص حلي عني مدخلكش بديش اقلك'],
            ['role' => 'user', 'message' => 'تبعثيليش'],
        ])['group']);
        $this->assertSame('refused', MansourPrompt::dialogueResult([
            ['role' => 'user', 'message' => 'ما خصك'],
        ])['group']);
        $this->assertSame('certain', MansourPrompt::dialogueResult([
            ['role' => 'user', 'message' => 'شو دخلك'],
            ['role' => 'assistant', 'message' => 'متأسف، مجرد استفتاء.'],
            ['role' => 'user', 'message' => 'بصوت للموحدة'],
        ])['group']);
        $this->assertSame('not_voting', MansourPrompt::dialogueResult([
            ['role' => 'user', 'message' => 'ما خصك'],
            ['role' => 'user', 'message' => 'ما بدي اصوت'],
        ])['group']);
        $this->assertNull(MansourPrompt::dialogueResult([
            ['role' => 'user', 'message' => 'شو دخلك'],
            ['role' => 'user', 'message' => 'رح أصوت'],
        ])['group']);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $this->chat($bot, 'brush', 'شو دخلك');
        $later = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972500000009@c.us',
            'title' => 'later',
        ]);
        foreach (['شو دخلك', 'بصوت للموحدة'] as $line) {
            ChatbotMessage::query()->create([
                'conversation_id' => $later->id,
                'role' => 'user',
                'message' => $line,
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.analytics', $bot))
            ->assertOk()
            ->assertJsonPath('analytics.refused', 1)
            ->assertJsonPath('analytics.certain', 1);

        $export = $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get(route('ai-chatbot.workspace.analytics.export', [$bot, 'refused']));
        $export->assertOk();
        $body = $export->streamedContent();
        $this->assertStringContainsString('رفض يكمل الحديث', $body);
        $this->assertStringContainsString('شو دخلك', $body);
        $disposition = rawurldecode((string) $export->headers->get('content-disposition'));
        $this->assertStringContainsString('بوت الفرز - رفض يكمل الحديث', $disposition);
    }

    public function test_ghazi_style_refusal_is_stored_as_boycott_with_phone_and_a_short_note(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $ghazi = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $user->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972542230842@c.us',
            'contact_name' => 'غازي عيسى',
            'contact_phone' => '972542230842',
            'title' => 'غازي عيسى',
        ]);
        foreach ([
            ['assistant', 'يسعد اوقاتك اخ غازي شو الاخبار'],
            ['user', 'الحمد لله مين معي ؟'],
            ['assistant', 'معك ديما، كيف الاحوال غازي؟ متاسفة إذا رسالتي إجت فجأة، احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا. في مجال أسألك سؤال صغير؟'],
            ['user', 'تفضلي'],
            ['user', 'من وين انت ؟'],
            ['assistant', 'بالنسبة لانتخابات 27.10، شو وضعك مع التصويت هالمرة؟ رح تصوّت ولا كيف؟'],
            ['user', 'لا اعوذ بالله'],
            ['assistant', 'شو اللي مخلّيك مش حابب تصوّت هالمرة؟'],
            ['user', 'يا رجو يا أختي على شفاضي، كله على شفاضي، ولا واحد منهم بستاهل.'],
        ] as [$role, $message]) {
            ChatbotMessage::query()->create([
                'conversation_id' => $ghazi->id,
                'role' => $role,
                'message' => $message,
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.analytics', $bot))
            ->assertOk()
            ->assertJsonPath('analytics.not_voting', 1)
            ->assertJsonPath('analytics.conversation_ids.not_voting.0', $ghazi->id);

        $export = $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.analytics.export', [$bot, 'not_voting']));
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('972542230842', $csv);
        $this->assertStringContainsString('لا يريد التصويت', $csv);
        $this->assertStringContainsString('ولا واحد منهم بستاهل', $csv);
    }

    public function test_each_vote_gets_its_reply_and_a_note_in_its_file(): void
    {
        $this->assertSame(MansourPrompt::UNITED_REPLY, MansourPrompt::scriptedReply('بصوت للموحدة'));
        $this->assertSame(MansourPrompt::JOINT_REPLY, MansourPrompt::scriptedReply('يا هي يا إما المشترك'));
        $this->assertSame(MansourPrompt::BOYCOTT_REPLY, MansourPrompt::scriptedReply('أنا مقاطع'));
        $this->assertSame(MansourPrompt::ZIONIST_REPLY, MansourPrompt::scriptedReply('بصوت لليكود'));
        $this->assertSame(MansourPrompt::PLACE_REPLY, MansourPrompt::scriptedReply('من أي مكان انت'));
        $this->assertSame(MansourPrompt::OFFICE_REPLY, MansourPrompt::scriptedReply('من أي مكتب'));
        $this->assertSame(MansourPrompt::STANCE_REPLY, MansourPrompt::scriptedReply('انت مع مين'));
        $this->assertSame(MansourPrompt::WHO_REPLY, MansourPrompt::scriptedReply('اهلين مين معي'));
        $this->assertSame(MansourPrompt::FOLLOW_UP_EXAMPLE, MansourPrompt::scriptedReply('اهلين', 'يسعد اوقاتك اخ فتحي شو الاخبار'));
        $this->assertSame(MansourPrompt::BOYCOTT_REPLY, MansourPrompt::scriptedReply('لا', 'ناوي تصوّت بالانتخابات؟'));
        $this->assertSame(MansourPrompt::BOYCOTT_REPLY, MansourPrompt::scriptedReply('لا مش رح يصوت'));
        $this->assertNull(MansourPrompt::scriptedReply('يا هلا'));
        $kept = new ChatbotInstance;
        $kept->system_prompt = 'إنت منصور. معك منصور، آسف إذا رسالتي إجت فجأة.';
        $prompt = MansourPrompt::resolve($kept);
        $this->assertStringNotContainsString('معك منصور', $prompt);
        $this->assertStringContainsString('معك ديما', $prompt);
        $this->assertStringNotContainsString('معي ديما', $prompt);
        $this->assertStringContainsString('مركز استطلاعات للمجتمع العربي', $prompt);
        $this->assertStringNotContainsString('من مكتب القائمة العربية الموحدة', $prompt);
        $this->assertNull(MansourPrompt::voteGroup('شو رايك بالموحدة'));
        $this->assertSame('certain', MansourPrompt::voteGroup('بصوت للموحدة'));
        $this->assertFalse(MansourPrompt::shouldPauseAfterReply('شو رايك بالموحدة', null));
        $this->assertTrue(MansourPrompt::shouldPauseAfterReply('بصوت للموحدة', null));
        $this->assertFalse(MansourPrompt::shouldPauseAfterReply('شو دخلك', null));
        $this->assertTrue(MansourPrompt::shouldPauseAfterReply('شو دخلك', MansourPrompt::pushbackAsk('فتحي')));
        $this->assertFalse(MansourPrompt::shouldSendContinuation('اهلين مين معي', 'يسعد اوقاتك اخ فتحي شو الاخبار', false));
        $this->assertStringContainsString('ديما', MansourPrompt::resolve(new ChatbotInstance));

        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'يسلموا، فرحتني بهالخبر 🌹'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'فتحي',
            'phone' => '972584680001',
            'phone_normalized' => '972584680001',
            'chat_id' => '972584680001@c.us',
            'triggered_at' => now(),
        ]);

        $reply = app(ChatbotGreenApiService::class)->handleWebhook(
            $bot,
            $this->whatsapp('972584680001@c.us', 'بصوت للموحدة', 'U1'),
        );

        $this->assertTrue($reply['listening'] ?? false);
        $this->settleMansour($reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'example.test')
            && ($request['message'] ?? null) === 'يسلموا، فرحتني بهالخبر 🌹');
        $this->assertSame(
            ChatbotConversation::BOT_MODE_PAUSED,
            $bot->conversations()->firstOrFail()->fresh()->bot_mode,
        );
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'api.openai.com')
            && ($request['model'] ?? null) === 'gpt-6-astra'
            && ($request['reasoning_effort'] ?? null) === 'high');

        $export = $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.analytics.export', [$bot, 'certain']));
        $export->assertOk();
        $this->assertStringContainsString('مصوت للقائمة العربية الموحدة', $export->streamedContent());
        $this->assertStringContainsString('972584680001', $export->streamedContent());
    }

    public function test_copy_keeps_its_own_prompt_and_numbers(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill(['system_prompt' => 'حملة أ'])->save();
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'سارة',
            'phone' => '0501111111',
            'phone_normalized' => '972501111111',
            'chat_id' => '972501111111@c.us',
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.conversations', $bot))
            ->assertOk()
            ->assertDontSee(route('ai-chatbot.workspace.mansour.copy', $bot), false);

        $this->actingAs($user)
            ->get(route('ai-chatbot.index', ['list' => 1]))
            ->assertOk()
            ->assertSee(route('ai-chatbot.workspace.mansour.copy', $bot), false);

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.copy', $bot))
            ->assertRedirect(route('ai-chatbot.index', ['list' => 1]));

        $copy = ChatbotInstance::query()->where('user_id', $user->id)->where('id', '!=', $bot->id)->firstOrFail();
        $this->assertSame('بوت الفرز 2', $copy->name);
        $this->assertSame('حملة أ', $copy->system_prompt);
        $this->assertSame(0, $copy->mansourContacts()->count());
        $this->assertNotSame($bot->greenapi_webhook_token, $copy->greenapi_webhook_token);
    }

    public function test_trigger_sends_only_the_chosen_numbers(): void
    {
        Http::fake(['*' => Http::response(['idMessage' => '1'], 200)]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
        ])->save();

        $csv = UploadedFile::fake()->createWithContent('people.csv', "name,phone\nأحمد,0501234567\nليلى,0507654321\n");
        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.contacts.store', $bot), ['excel' => $csv])
            ->assertRedirect();

        $this->assertSame(2, $bot->mansourContacts()->count());
        $chosen = $bot->mansourContacts()->orderBy('id')->firstOrFail();

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$chosen->id],
            ])
            ->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request['message'] ?? null) === 'يسعد اوقاتك اخ أحمد شو الاخبار');
        $chosen->refresh();
        $this->assertNotNull($chosen->triggered_at);
        $this->assertSame(1, $bot->mansourContacts()->whereNull('triggered_at')->count());

        $conversation = $bot->conversations()->firstOrFail();
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message' => 'لا مش رح يصوت',
        ]);
        $conversation->forceFill(['bot_mode' => ChatbotConversation::BOT_MODE_PAUSED])->save();

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$chosen->id],
            ])
            ->assertRedirect();

        $conversation->refresh();
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->bot_mode);
        $this->assertSame(
            ['يسعد اوقاتك اخ أحمد شو الاخبار'],
            $conversation->messages()->orderBy('id')->pluck('message')->all(),
        );
    }

    public function test_live_send_stays_in_the_dialog_and_can_be_opened_later(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'getStateInstance')) {
                return Http::response(['stateInstance' => 'authorized'], 200);
            }
            $chat = (string) ($request['chatId'] ?? '');
            if (str_contains($chat, '972501234567')) {
                return Http::response(['message' => 'instance is not authorized'], 400);
            }

            return Http::response(['idMessage' => 'ok-1'], 200);
        });

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
        ])->save();

        $csv = UploadedFile::fake()->createWithContent('people.csv', "name,phone\nأحمد,0501234567\nليلى,0507654321\n");
        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.contacts.store', $bot), ['excel' => $csv])
            ->assertRedirect();

        $contacts = $bot->mansourContacts()->orderBy('id')->get();
        $first = $contacts[0];
        $second = $contacts[1];

        $failed = $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$first->id],
            ]);
        $failed->assertOk();
        $failed->assertJsonPath('rows.0.ok', false);
        $failed->assertJsonPath('rows.0.code', 'http');
        $this->assertStringContainsString('400', (string) $failed->json('rows.0.human'));
        $this->assertStringContainsString('instance is not authorized', (string) $failed->json('rows.0.human'));
        $runId = $failed->json('run_id');

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$second->id],
                'run_id' => $runId,
                'finish' => true,
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', true)
            ->assertJsonPath('rows.0.code', 'accepted')
            ->assertJsonPath('rows.0.human', __('chatbot.mansour.debug_accepted'));

        $run = MansourSendRun::query()->where('instance_id', $bot->id)->firstOrFail();
        $this->assertSame(1, $run->sent);
        $this->assertSame(1, $run->failed);
        $this->assertNotNull($run->finished_at);
        $this->assertCount(2, $run->results);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.mansour.sends', $bot))
            ->assertOk()
            ->assertJsonPath('runs.0.id', $run->id)
            ->assertJsonPath('runs.0.sent', 1)
            ->assertJsonPath('runs.0.failed', 1)
            ->assertJsonPath('runs.0.finished', true);
    }

    public function test_a_send_that_never_reaches_whatsapp_is_reported_instead_of_left_waiting(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://7103.api.greenapi.com/waInstance1/sendMessage/token',
        ])->save();
        $contact = $bot->mansourContacts()->create([
            'name' => 'يامن',
            'phone' => '0505133718',
            'phone_normalized' => '972505133718',
            'chat_id' => '972505133718@c.us',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [$contact->id],
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', false)
            ->assertJsonPath('rows.0.code', 'request_failed')
            ->assertJsonPath('rows.0.human', __('chatbot.mansour.debug_request_failed'))
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('failed', 1);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [999999],
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.ok', false)
            ->assertJsonPath('rows.0.contact_id', 999999)
            ->assertJsonPath('failed', 1);
    }

    public function test_dima_send_reports_that_outbound_is_off(): void
    {
        config(['dima.outbound' => false]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = app(\App\Services\AiChatbot\MansourBotService::class)->ensureDimaBot($user);
        $this->assertInstanceOf(ChatbotInstance::class, $bot);

        $this->actingAs($user)
            ->withHeader('X-Mansour-Live', '1')
            ->postJson(route('ai-chatbot.workspace.mansour.trigger', $bot), [
                'contact_ids' => [1],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('chatbot.mansour.dima_outbound_off'));
    }

    public function test_continuation_waits_for_the_person_to_reply(): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'ما لازم نوصل لهون'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'فتحي',
            'phone' => '972584680001',
            'phone_normalized' => '972584680001',
            'chat_id' => '972584680001@c.us',
            'triggered_at' => now(),
        ]);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $bot->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972584680001@c.us',
            'contact_name' => 'فتحي',
            'contact_phone' => '972584680001',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'يسعد اوقاتك اخ فتحي شو الاخبار',
        ]);

        $reply = app(ChatbotGreenApiService::class)->handleWebhook(
            $bot,
            $this->whatsapp('972584680001@c.us', 'اهلين', 'C1'),
        );

        $this->assertTrue($reply['listening'] ?? false);
        $this->settleMansour($reply);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'example.test')
            && ($request['message'] ?? null) === 'معك ديما، كيف الاحوال فتحي؟ متاسفة إذا رسالتي إجت فجأة، احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا. في مجال أسألك سؤال صغير؟');
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'api.openai.com'));
    }

    public function test_permission_answer_asks_whether_they_will_vote_on_the_date(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'طيب خليني أسألك عن الانتخابات بـ 27.10، شو وضعك مصوت ولا كيف؟'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();

        foreach (['972584680011' => 'لا', '972584680012' => 'اه تفضل'] as $phone => $text) {
            MansourContact::query()->create([
                'instance_id' => $bot->id,
                'name' => 'فتحي',
                'phone' => $phone,
                'phone_normalized' => $phone,
                'chat_id' => $phone.'@c.us',
                'triggered_at' => now(),
            ]);
            $conversation = ChatbotConversation::query()->create([
                'user_id' => $user->id,
                'instance_id' => $bot->id,
                'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                'external_chat_id' => $phone.'@c.us',
                'contact_name' => 'فتحي',
                'contact_phone' => $phone,
                'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            ]);
            ChatbotMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'message' => 'معك ديما، كيف الاحوال فتحي؟ متاسفة إذا رسالتي إجت فجأة، احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا. في مجال أسألك سؤال صغير؟',
                'delivery_status' => 'sent',
            ]);

            $reply = app(ChatbotGreenApiService::class)->handleWebhook(
                $bot,
                $this->whatsapp($phone.'@c.us', $text, 'P'.$phone),
            );
            $this->settleMansour($reply);
            $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->fresh()->bot_mode);
        }

        $blobs = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.openai.com'))
            ->map(fn (array $pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
            ->values();
        $this->assertCount(2, $blobs);
        foreach ($blobs as $blob) {
            $this->assertStringContainsString('بالنسبة للانتخابات هالمرة شو وضعك؟ ناوي تصوّت ولا كيف؟', $blob);
            $this->assertStringNotContainsString('فتحي بالنسبة للانتخابات', $blob);
            $this->assertStringContainsString('بلا «يا» قبل الاسم', $blob);
            $this->assertStringContainsString('ذكر', $blob);
            $this->assertStringContainsString('سواء وافق أو رفض', $blob);
        }

        $phone = '972584680013';
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'ليلى',
            'phone' => $phone,
            'phone_normalized' => $phone,
            'chat_id' => $phone.'@c.us',
            'triggered_at' => now(),
        ]);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $bot->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => $phone.'@c.us',
            'contact_name' => 'ليلى',
            'contact_phone' => $phone,
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'معك ديما، كيف الاحوال ليلى؟ متاسفة إذا رسالتي إجت فجأة، احنا مجموعة بنحب وسطنا العربي، وحابين نعرف إذا الناس حابة تطلع تصوّت هالمرة ولا لا. في مجال أسألك سؤال صغير؟',
            'delivery_status' => 'sent',
        ]);
        $reply = app(ChatbotGreenApiService::class)->handleWebhook(
            $bot,
            $this->whatsapp($phone.'@c.us', 'تفضلي', 'P'.$phone),
        );
        $this->settleMansour($reply);
        $female = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.openai.com'))
            ->map(fn (array $pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
            ->last();
        $this->assertIsString($female);
        $this->assertStringContainsString('بالنسبة للانتخابات هالمرة شو وضعك؟ ناوية تصوّتي ولا كيف؟', $female);
        $this->assertStringContainsString('أنثى', $female);
    }

    public function test_selected_numbers_can_be_deleted_from_the_list(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $csv = UploadedFile::fake()->createWithContent('people.csv', "name,phone\nأحمد,0501234567\nليلى,0507654321\n");
        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.mansour.contacts.store', $bot), ['excel' => $csv])
            ->assertRedirect();

        $keep = $bot->mansourContacts()->where('phone_normalized', '972501234567')->firstOrFail();
        $drop = $bot->mansourContacts()->where('phone_normalized', '972507654321')->firstOrFail();
        $drop->forceFill(['triggered_at' => now()])->save();

        $this->actingAs($user)
            ->deleteJson(route('ai-chatbot.workspace.mansour.contacts.destroy', $bot), [
                'contact_ids' => [$drop->id],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertSame([$keep->id], $bot->mansourContacts()->pluck('id')->all());
    }

    public function test_scientific_excel_phones_are_stored_as_israeli_whatsapp_ids(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $bot->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '5489785738@c.us',
            'contact_name' => 'محمود ناضم ريان',
            'contact_phone' => '5489785738',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
        ]);
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'محمود ناضم ريان',
            'phone' => '5489785738',
            'phone_normalized' => '5489785738',
            'chat_id' => '5489785738@c.us',
            'conversation_id' => $conversation->id,
            'triggered_at' => now(),
            'last_error' => 'send_failed',
        ]);
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'حسام نعيم',
            'phone' => '533917088',
            'phone_normalized' => '533917088',
            'chat_id' => '533917088@c.us',
        ]);

        $path = $this->scientificPhoneWorkbook();
        try {
            $file = new UploadedFile($path, 'youth.xlsx', null, null, true);
            $added = app(\App\Services\AiChatbot\MansourBotService::class)->replaceContacts($bot, $file);
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, $added);
        $fixed = $bot->mansourContacts()->where('name', 'محمود ناضم ريان')->firstOrFail();
        $this->assertSame('972548978573', $fixed->phone_normalized);
        $this->assertSame('972548978573@c.us', $fixed->chat_id);
        $this->assertNotNull($fixed->triggered_at);
        $this->assertNull($fixed->last_error);
        $conversation->refresh();
        $this->assertSame('972548978573@c.us', $conversation->external_chat_id);
        $this->assertSame('972548978573', $conversation->contact_phone);

        $this->assertNull($bot->mansourContacts()->where('phone_normalized', '533917088')->first());
        $this->assertSame('972533917080', $bot->mansourContacts()->where('name', 'حسام نعيم')->value('phone_normalized'));
        $this->assertSame('972559630100', $bot->mansourContacts()->where('name', 'عيسى زياد منزل')->value('phone_normalized'));
        $this->assertSame(3, $bot->mansourContacts()->count());
    }

    private function scientificPhoneWorkbook(): string
    {
        $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<si><t>الاسم الثلاثي</t></si><si><t>رقم الهاتف</t></si><si><t>البلدة</t></si>'
            .'<si><t>محمود ناضم ريان</t></si><si><t>كابول</t></si>'
            .'<si><t>حسام نعيم</t></si><si><t>أبو سنان</t></si>'
            .'<si><t>عيسى زياد منزل</t></si><si><t>كفر كنا</t></si>'
            .'</sst>';
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="n"><v>5.48978573E8</v></c><c r="C2" t="s"><v>4</v></c></row>'
            .'<row r="3"><c r="A3" t="s"><v>5</v></c><c r="B3" t="n"><v>5.3391708E8</v></c><c r="C3" t="s"><v>6</v></c></row>'
            .'<row r="4"><c r="A4" t="s"><v>7</v></c><c r="B4" t="n"><v>5.596301E+8</v></c><c r="C4" t="s"><v>8</v></c></row>'
            .'</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            $this->fail('Could not create a temp workbook.');
        }
        @unlink($path);
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', $shared);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }

    public function test_a_custom_number_can_be_added_and_named_in_the_opening(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.contacts.custom', $bot), [
                'name' => 'سامر',
                'phone' => '0502223344',
            ])
            ->assertOk()
            ->assertJsonPath('contact.name', 'سامر');

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.contacts.custom', $bot), [
                'phone' => 'abc',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.mansour.contacts.custom', $bot), [
                'name' => '',
                'phone' => '0503334455',
            ])
            ->assertOk()
            ->assertJsonPath('contact.name', '');

        $this->assertSame(2, $bot->mansourContacts()->count());
        $this->assertSame(
            'يسعد اوقاتك، شو الاخبار؟',
            \App\Services\AiChatbot\MansourPrompt::openingFor($bot, null),
        );
        $this->assertSame(
            'يسعد اوقاتك اخ أحمد شو الاخبار',
            MansourPrompt::openingFor($bot, 'أحمد'),
        );
        $this->assertSame(
            'يسعد اوقاتك اخ حمزة شو الاخبار',
            MansourPrompt::openingFor($bot, 'حمزة'),
        );
        $this->assertSame(
            'يسعد اوقاتك اخت ليلى شو الاخبار',
            MansourPrompt::openingFor($bot, 'ليلى'),
        );
        $this->assertSame(
            'يسعد اوقاتك اخت فاطمه شو الاخبار',
            MansourPrompt::openingFor($bot, 'فاطمه'),
        );
        $this->assertSame(
            'يسعد اوقاتك اخ ابو محمد شو الاخبار',
            MansourPrompt::openingFor($bot, 'ابو محمد'),
        );
        $this->assertSame(
            'يسعد اوقاتك اخت ام احمد شو الاخبار',
            MansourPrompt::openingFor($bot, 'ام احمد'),
        );
        $this->assertSame(
            MansourPrompt::NAMELESS_FOLLOW_UP,
            MansourPrompt::continuationFor(''),
        );
        $this->assertStringContainsString('أنثى', MansourPrompt::addressInstruction('ليلى'));
        $this->assertStringContainsString('ذكر', MansourPrompt::addressInstruction(''));
        $this->assertStringContainsString('تصوّتي', MansourPrompt::voteYesNoInstruction('female'));
        $this->assertStringContainsString(
            MansourPrompt::FOLLOW_UP_EXAMPLE,
            MansourPrompt::resolve($bot),
        );
    }

    public function test_mansour_uses_the_latest_chat_model(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $captured = $request->data();

                return Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'تمام.'],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        app(AiChatbotService::class)->sendMessage($user, $bot, 'مرحبا', null, [
            'channel' => ChatbotConversation::CHANNEL_TEST,
        ]);

        $this->assertSame('gpt-6-astra', $captured['model'] ?? null);
        $this->assertSame('high', $captured['reasoning_effort'] ?? null);
    }

    public function test_whatsapp_replies_only_to_triggered_numbers_with_a_human_pause(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'تمام، شو الأخبار؟'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();

        $green = app(ChatbotGreenApiService::class);
        $stranger = $green->handleWebhook($bot, $this->whatsapp('972501111111@c.us', 'مرحبا', 'S1'));
        $this->assertTrue($stranger['ignored'] ?? false);
        $this->assertSame(0, $bot->conversations()->count());

        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'ليلى',
            'phone' => '972502222222',
            'phone_normalized' => '972502222222',
            'chat_id' => '972502222222@c.us',
        ]);
        $waiting = $green->handleWebhook($bot, $this->whatsapp('972502222222@c.us', 'مرحبا', 'S2'));
        $this->assertTrue($waiting['ignored'] ?? false);
        Http::assertNothingSent();

        $contact = MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'أحمد',
            'phone' => '972503333333',
            'phone_normalized' => '972503333333',
            'chat_id' => '972503333333@c.us',
            'triggered_at' => now(),
        ]);
        $reply = $green->handleWebhook($bot, $this->whatsapp('972503333333@c.us', 'شو الأخبار', 'S3'));
        $this->assertTrue($reply['listening'] ?? false);
        $this->settleMansour($reply);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com')
            && ($request['model'] ?? null) === 'gpt-6-astra'
            && ($request['reasoning_effort'] ?? null) === 'high');
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'example.test')) {
                return false;
            }

            return ($request['chatId'] ?? null) === '972503333333@c.us'
                && (int) ($request['typingTime'] ?? 0) >= 1000
                && (int) ($request['typingTime'] ?? 0) <= 20000;
        });
        $this->assertSame(1, $bot->conversations()->count());
        $this->assertNotNull($contact->id);
    }

    public function test_a_brush_off_gets_the_area_poll_explanation(): void
    {
        $this->assertTrue(MansourPrompt::isPushback('شو دخلك'));
        $this->assertTrue(MansourPrompt::isPushback('مدخلكش'));
        $this->assertTrue(MansourPrompt::isPushback('خلص حلي عني مدخلكش بديش اقلك'));
        $this->assertTrue(MansourPrompt::isPushback('تبعثيليش'));
        $this->assertTrue(MansourPrompt::isPushback('شو خصك ليش بتسأل'));
        $this->assertTrue(MansourPrompt::isPushback('شو خصك انو انت'));
        $this->assertFalse(MansourPrompt::isPushback('يا هلا'));
        $this->assertFalse(MansourPrompt::isPushback('رح أصوت للمشتراك'));
        $this->assertSame(
            'متأسفة ليلى، هاد بس استفتاء للمنطقة مش اكثر. بتمنى ما ازعجتك 🙏 بس حابة أعرف إذا رح تصوّتي ولا لا.',
            MansourPrompt::pushbackAsk('ليلى', 'female'),
        );

        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'يا ريت لو تكتب، الصوت مش كثير واضح.'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'فتحي',
            'phone' => '972584680001',
            'phone_normalized' => '972584680001',
            'chat_id' => '972584680001@c.us',
            'triggered_at' => now(),
        ]);

        $reply = app(ChatbotGreenApiService::class)->handleWebhook(
            $bot,
            $this->whatsapp('972584680001@c.us', 'شو خصك انو انت', 'P1'),
        );

        $this->assertTrue($reply['listening'] ?? false);
        $this->settleMansour($reply);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'example.test')
            && ($request['message'] ?? null) === 'متأسفة فتحي، هاد بس استفتاء للمنطقة مش اكثر. بتمنى ما ازعجتك 🙏 بس حابة أعرف إذا رح تصوّت ولا لا.');

        $again = app(ChatbotGreenApiService::class)->handleWebhook(
            $bot,
            $this->whatsapp('972584680001@c.us', 'شو دخلك', 'P2'),
        );
        $this->settleMansour($again);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'example.test')
            && ($request['message'] ?? null) === 'متأسفة فتحي، يعطيك العافية.');
        $this->assertSame(
            ChatbotConversation::BOT_MODE_PAUSED,
            ChatbotConversation::query()->find($again['conversation_id'])?->bot_mode,
        );
    }

    public function test_a_broken_voice_transcript_asks_them_to_type(): void
    {
        $this->assertTrue(MansourPrompt::voiceTranscriptIsBroken('يا الواحد يا'));
        $this->assertTrue(MansourPrompt::voiceTranscriptIsBroken('يا واحد يا...'));
        $this->assertFalse(MansourPrompt::voiceTranscriptIsBroken('رح أصوت للمشتراك'));
        $this->assertFalse(MansourPrompt::voiceTranscriptIsBroken('أكيد بصوت'));
        $this->assertStringNotContainsString(
            MansourPrompt::UNCLEAR_VOICE_REPLY,
            MansourPrompt::resolve(new ChatbotInstance),
        );
        $this->assertSame(
            'تمام، لمين ناوي تصوّت؟',
            MansourPrompt::withoutVoiceComplaint('تمام، لمين ناوي تصوّت؟ يا ريت لو تكتب، الصوت مش كثير واضح.'),
        );
    }

    public function test_typing_pause_grows_with_the_reply(): void
    {
        $short = MansourHumanDelay::millisecondsForText('أهلا');
        $long = MansourHumanDelay::millisecondsForText(str_repeat('بدي أحكي معك شوي عن التصويت. ', 12));

        $this->assertGreaterThanOrEqual(1000, $short);
        $this->assertLessThanOrEqual(2000, $short);
        $this->assertGreaterThanOrEqual(10000, $long);
        $this->assertGreaterThan($short, $long);
    }

    public function test_split_messages_wait_and_regenerate_one_reply(): void
    {
        config([
            'services.openai.api_key' => 'test-key',
            'openai.mansour_chat_model' => 'gpt-6-astra',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'تمام، شكراً إنك وضحت.'],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => '1'], 200),
        ]);

        $this->seed(WorkspaceUserSeeder::class);
        $user = User::query()->where('email', 'mowahada@workspace.com')->firstOrFail();
        $bot = ChatbotInstance::query()->where('user_id', $user->id)->firstOrFail();
        $bot->forceFill([
            'greenapi_url' => 'https://example.test/waInstance1/sendMessage/token',
        ])->save();
        MansourContact::query()->create([
            'instance_id' => $bot->id,
            'name' => 'فتحي',
            'phone' => '972584680001',
            'phone_normalized' => '972584680001',
            'chat_id' => '972584680001@c.us',
            'triggered_at' => now(),
        ]);
        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $bot->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972584680001@c.us',
            'contact_name' => 'فتحي',
            'contact_phone' => '972584680001',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'يسعد اوقاتك اخ فتحي شو الاخبار',
            'delivery_status' => 'sent',
        ]);

        $green = app(ChatbotGreenApiService::class);
        $first = $green->handleWebhook($bot, $this->whatsapp('972584680001@c.us', 'اهلين', 'B1'));
        $second = $green->handleWebhook($bot, $this->whatsapp('972584680001@c.us', 'بصوت للموحدة', 'B2'));
        $this->settleMansour($first);
        $this->settleMansour($second);

        $openai = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.openai.com'))
            ->values();
        $this->assertCount(1, $openai);
        $blob = json_encode($openai[0][0]->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('اهلين', $blob);
        $this->assertStringContainsString('بصوت للموحدة', $blob);

        $sent = collect(Http::recorded())
            ->filter(fn (array $pair) => ($pair[0]->data()['message'] ?? null) === 'تمام، شكراً إنك وضحت.')
            ->count();
        $this->assertSame(1, $sent);
        $this->assertSame(
            ChatbotConversation::BOT_MODE_PAUSED,
            $conversation->fresh()->bot_mode,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function settleMansour(array $result): void
    {
        (new ProcessMansourConversationJob(
            (int) $result['instance_id'],
            (int) $result['conversation_id'],
            (int) $result['conversation_version'],
            (string) $result['chatId'],
        ))->handle(
            app(AiChatbotService::class),
            app(ChatbotGreenApiService::class),
            app(MansourConversationBurstService::class),
        );
    }

    private function whatsapp(string $chatId, string $text, string $messageId): Request
    {
        return Request::create('/webhook', 'POST', [
            'typeWebhook' => 'incomingMessageReceived',
            'idMessage' => $messageId,
            'senderData' => [
                'chatId' => $chatId,
                'senderName' => 'Test',
            ],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => ['textMessage' => $text],
                'fromMe' => false,
            ],
        ]);
    }

    private function chat(ChatbotInstance $bot, string $key, string $text): void
    {
        $conversation = ChatbotConversation::query()->create([
            'instance_id' => $bot->id,
            'user_id' => $bot->user_id,
            'external_chat_id' => '97250000000'.$key.'@c.us',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'title' => $key,
        ]);
        ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message' => $text,
        ]);
    }
}
