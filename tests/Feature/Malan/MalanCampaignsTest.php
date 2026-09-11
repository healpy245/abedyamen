<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Models\User;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\Malan\Campaigns\MalanCampaignExcelParser;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MalanCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function malanInstance(User $user): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
            'system_prompt' => 'You are Sally for Malan.',
        ]);
    }

    public function test_campaigns_page_is_reachable_for_malan(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns', $instance))
            ->assertOk();
    }

    public function test_excel_parser_reads_csv_name_phone(): void
    {
        $csv = "name,phone,city\nAbed,0533046830,Nablus\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $rows = app(MalanCampaignExcelParser::class)->parse($file);

        $this->assertCount(1, $rows);
        $this->assertSame('Abed', $rows[0]['name']);
        $this->assertSame('0533046830', $rows[0]['phone']);
        $this->assertSame('Nablus', $rows[0]['city']);
    }

    public function test_chat_id_is_built_with_country_code_from_bare_mobile(): void
    {
        $service = app(\App\Services\Malan\Campaigns\MalanCampaignService::class);
        $contact = new MalanCampaignContact([
            'phone' => '584680001',
            'phone_normalized' => '584680001',
            'chat_id' => '584680001@c.us',
        ]);

        $this->assertSame('972584680001@c.us', $service->rebuildChatIdForContact($contact));
        $this->assertSame('0584680001', $service->normalizePhoneDigits('584680001'));
        $this->assertSame('972533046830@c.us', $service->rebuildChatIdForContact(new MalanCampaignContact([
            'phone' => '0533046830',
            'phone_normalized' => '0533046830',
        ])));
    }

    public function test_campaign_conversation_is_leads_only_for_tools(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Spring',
            'status' => MalanCampaign::STATUS_READY,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972533046830@c.us',
            'contact_phone' => '972533046830',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $tools = app(ChatbotToolDefinitions::class)->forInstance($instance, 'whatsapp', false, $conversation);
        $this->assertCount(1, $tools);
        $this->assertSame('create_malan_lead', $tools[0]['function']['name']);

        $blocked = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'phone', 'value' => '0533046830', 'reason' => 'account_status'],
            'whatsapp',
        );

        $this->assertFalse($blocked['success']);
        $this->assertSame('campaign_leads_only', $blocked['error_code'] ?? null);
    }

    public function test_create_campaign_from_csv_upload(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $csv = "name,phone\nSara,0524060606\n";

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.campaigns.store', $instance), [
                'name' => 'Lead push',
                'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/token',
                'opening_message' => 'مرحبا',
                'excel' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('malan_campaigns', [
            'chatbot_instance_id' => $instance->id,
            'name' => 'Lead push',
            'contacts_count' => 1,
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => 1,
        ]);

        $campaign = MalanCampaign::query()
            ->where('chatbot_instance_id', $instance->id)
            ->where('name', 'Lead push')
            ->firstOrFail();
        $this->assertNotSame('', trim((string) $campaign->system_prompt));
    }

    public function test_campaign_bot_toggle_is_independent_of_instance_bot(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $instance->forceFill(['is_active' => true])->save();

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Taybee',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => 'Campaign-only prompt',
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972533046830@c.us',
            'contact_phone' => '972533046830',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.campaigns.bot-active', [$instance, $campaign]), [
                'is_active' => '0',
            ])
            ->assertRedirect();

        $campaign->refresh();
        $conversation->refresh();
        $instance->refresh();

        $this->assertFalse($campaign->isBotActive());
        $this->assertTrue($instance->isBotGloballyActive());
        $this->assertSame(ChatbotConversation::BOT_MODE_PAUSED, $conversation->bot_mode);

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.campaigns.bot-active', [$instance, $campaign]), [
                'is_active' => '1',
            ])
            ->assertRedirect();

        $campaign->refresh();
        $conversation->refresh();
        $this->assertTrue($campaign->isBotActive());
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->bot_mode);
    }

    public function test_campaign_uses_its_own_system_prompt(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Prompted',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => 'ONLY CAMPAIGN PROMPT XYZ',
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500000001@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $this->assertSame('ONLY CAMPAIGN PROMPT XYZ', $campaign->resolvedSystemPrompt());
        $this->assertTrue($conversation->isCampaignLeadBot());
    }

    public function test_default_campaign_prompt_is_sales_focused_not_central_intake(): void
    {
        $prompt = MalanCampaign::defaultLeadSystemPrompt();

        $this->assertStringContainsString('29', $prompt);
        $this->assertStringContainsString('149', $prompt);
        $this->assertStringContainsString('عربية', $prompt);
        $this->assertStringContainsString('بنفس اليوم', $prompt);
        $this->assertStringContainsString('منفصلة تمامًا', $prompt);
        $this->assertStringContainsString('يضل يقرأ', $prompt);
        $this->assertStringContainsString('رخص', $prompt);
        $this->assertStringContainsString('اكيد بتعرفنا صح', $prompt);
        $this->assertStringContainsString('مثل ما بتعرف', $prompt);
        $this->assertStringContainsString('اذا حابب تنضم النا', $prompt);
        $this->assertStringContainsString('دفعة رسائل', $prompt);
        $this->assertStringContainsString('ممنوع فصحى', $prompt);
        $this->assertStringContainsString('انا سالي من شركة ملان', $prompt);
        $this->assertStringContainsString('משרד התקשורת', $prompt);
        $this->assertStringContainsString('الشركة العربية الاولى في المنطقة', $prompt);
        $this->assertStringContainsString('סיב אופטי', $prompt);
        $this->assertStringContainsString('بعد نجاح رهيب في 5 بلدات', $prompt);
        $this->assertStringContainsString('الطيبة البلد السادس', $prompt);
        $this->assertStringContainsString('دعم تقني على مدار الساعة', $prompt);
        $this->assertStringContainsString('3) عندكم סיב אופטי بالبيت ولا لسه؟', $prompt);
        $this->assertStringContainsString('# سؤال الإغلاق', $prompt);
        $this->assertStringContainsString('ليش لا', $prompt);
        $this->assertStringContainsString('واستنّي جواب اه أو لا من الزبون', $prompt);
        $this->assertStringContainsString('# ميزات الخدمة — بس إذا سأل الزبون', $prompt);
        $this->assertStringContainsString('مرتاح مع بيزك', $prompt);
        $this->assertStringContainsString('بقترح عليك تعطي فرصة', $prompt);
        $this->assertStringContainsString('استغل السعر', $prompt);
        $this->assertStringContainsString('300-500', $prompt);
        $this->assertStringContainsString('تنبسط', $prompt);
        $this->assertStringNotContainsString('رح ينبسط', $prompt);
        $this->assertStringContainsString('ونهارك سعيد', $prompt);
        $this->assertStringContainsString('قسم المبيعات', $prompt);
        $this->assertStringContainsString('ذكاء اصطناعي', $prompt);
        $this->assertStringContainsString('من 9 الصبح لـ 6 المسا', $prompt);
        $this->assertStringContainsString('ممنوع التكرار', $prompt);
        $this->assertStringContainsString('https://www.instagram.com/reel/DOs3eKXDNa7/?igsi=YWJkd3M3aWllZDRm', $prompt);
        $this->assertStringContainsString('# فقعة الحلول وآراء الزباين', $prompt);
        $this->assertStringContainsString('بنص المحادثة', $prompt);
        $this->assertStringContainsString('ما في داعي تحكيها إلا إذا الزبون سأل', $prompt);
        $this->assertStringContainsString('من الألف للياء', $prompt);
        $this->assertStringContainsString('الجمعة والسبت', $prompt);
        $this->assertStringContainsString('מגדיל טווח', $prompt);
        $this->assertStringContainsString('ممنوع تمامًا أي استخدام لجملة', $prompt);
        $this->assertStringContainsString('مش رح اوخذ من وقتك', $prompt);
        $this->assertStringContainsString('بدك اخليها تتواصل معك عهاذ الرقم', $prompt);
        $this->assertStringContainsString('تمام، اعطيني اسمك الكامل بس', $prompt);
        $this->assertStringContainsString('رح تتواصل معك الصبيه كمان شوي', $prompt);
        $this->assertStringContainsString('תשתית וספק', $prompt);
        $this->assertStringContainsString('بدون أي شرطة قبل ספק', $prompt);
        $this->assertStringContainsString('whatsapp_chat_phone', $prompt);
        $this->assertStringContainsString('city_name = الطيبة', $prompt);
        $this->assertStringContainsString('وانا شو اعملكم', $prompt);
        $this->assertStringContainsString('حلي عني', $prompt);
        $this->assertStringNotContainsString('تمام، بدي منك الاسم الكامل ورقم تلفون للتواصل', $prompt);
        $this->assertStringNotContainsString('حقل ورا التاني (اسم → تلفون → بلدة)', $prompt);
        $this->assertStringNotContainsString('انا من מחלקת המכירות', $prompt);
        $this->assertStringNotContainsString('يا هلا فيك', $prompt);
        $this->assertStringNotContainsString('90 بالميه', $prompt);
        $this->assertStringNotContainsString('רשיונות من משרד תקשורת', $prompt);
        $this->assertStringNotContainsString('جديد بلشنا بالطيبه', $prompt);
        $this->assertStringNotContainsString('1000 ميجا', $prompt);
        $this->assertStringNotContainsString('هدفك الوحيد: جمع ليد', $prompt);
        $this->assertStringNotContainsString('شركة الانترنت العربية الوحيدة', $prompt);
        $this->assertStringNotContainsString('كفر قاسم', $prompt);
        $this->assertStringNotContainsString('6000 زبون', $prompt);
        $this->assertStringNotContainsString('سنة من تجهيز', $prompt);
        $this->assertStringNotContainsString('3) حابين تكونوا', $prompt);
        $this->assertStringNotContainsString('4) والميزة عنا', $prompt);
        $this->assertStringNotContainsString('# ميزات الخدمة (تلقائية', $prompt);
        $this->assertStringNotContainsString('رفض / انزعاج — وقف فوري', $prompt);
    }

    public function test_migration_refreshes_intro_copy_on_existing_campaign_prompts(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);

        $oldPrompt = <<<'TEXT'
## إذا قال إنه بعرف ملان
مثل ما بتعرف، احنا شركة الانترنت العربية الوحيدة الي حاصلة على رخص من משרד התקשורת في المركز، وبنقدم للزبون תשתית וספק. اليوم بنشتغل بـ6 بلاد عربية وعنا أكثر من 6000 زبون.

1) احنا شركة الانترنت العربية الوحيدة الي حاصلة على رخص من משרד התקשורת في المركز، وبنقدم للزبون תשתית וספק.

2) وطبعا بعد مرور سنة من تجهيز الـתשתית بالطيبة، رسميا بلشنا نتواصل مع زباين من الطيبة للانضمام لعائلة ملان.

3) بلشنا من كفر قاسم وبعد النجاح الكبير انتقلنا لـ6 بلاد عربية، واليوم عنا أكثر من 6000 زبون.

ترتيب إلزامي: الطيبة (فقعة 2) قبل كفر قاسم/6000 (فقعة 3) — ممنوع تعكسيهن. ممنوع تبعتي كفر قاسم قبل جملة سنة التجهيز بالطيبة.

مهم: اكتبِي دائمًا «תשתית וספק» بدون أي شرطة قبل ספק — ممنوع «תשתית ו־ספק» أو «תשתית و־ספק».

# متى تقولي «بالمناسبه» (مهم)
«بالمناسبه» أداة انتقال بس.
TEXT;

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Legacy prompt',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            // Prompts saved through the settings textarea come back with CRLF.
            'system_prompt' => str_replace("\n", "\r\n", $oldPrompt),
            'contacts_count' => 0,
        ]);

        $migration = require database_path('migrations/2026_08_26_120000_refresh_malan_campaign_intro_copy.php');
        $migration->up();
        $afterFirstPass = (string) $campaign->fresh()->system_prompt;

        $migration->up();
        $refreshed = (string) $campaign->fresh()->system_prompt;
        $this->assertSame($afterFirstPass, $refreshed, 'refresh must be idempotent');

        $this->assertStringNotContainsString('شركة الانترنت العربية الوحيدة', $refreshed);
        $this->assertStringNotContainsString('كفر قاسم', $refreshed);
        $this->assertStringNotContainsString('سنة من تجهيز', $refreshed);
        $this->assertStringContainsString('ملان انترنت الشركة العربية الاولى في المنطقة لتقديم خدمات الانترنت السريع סיב אופטי', $refreshed);
        $this->assertStringContainsString('بعد نجاح رهيب في 5 بلدات', $refreshed);
        $this->assertStringContainsString('https://www.instagram.com/reel/DOs3eKXDNa7/?igsi=YWJkd3M3aWllZDRm', $refreshed);
        $this->assertStringContainsString('3) حابين تكونوا جزء من نجاحنا في الطيبة.', $refreshed);
        $this->assertStringContainsString('ما في داعي تحكيها إلا إذا الزبون سأل', $refreshed);
        $this->assertStringContainsString('# متى تقولي «بالمناسبه» (مهم)', $refreshed);
        $this->assertSame(1, substr_count($refreshed, '# תשתית וספק — بس إذا سأل الزبون'));
        $this->assertSame(1, substr_count($refreshed, 'مهم: اكتبِي دائمًا'));
    }

    public function test_migration_makes_closing_question_and_ask_only_advantages(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);

        $oldPrompt = <<<'TEXT'
## إذا قال إنه بعرف ملان
بعدها — تلقائيًا وبنفس الرد أو برسالة تالية مباشرة بدون ما تستنّي سؤال — كمّلي فقرة الطيبة ثم ميزات الخدمة (تحت).

3) حابين تكونوا جزء من نجاحنا في الطيبة.

4) والميزة عنا إنك بتتعامل مع شركة عربية من الألف للياء، من التركيب للدعم الفني. الخدمة عنا شغالة كمان الجمعة والسبت، وإذا صار عندك أي مشكلة هدفنا نحلها بنفس اليوم، وبحد أقصى ثاني يوم.

ترتيب إلزامي: التعريف (فقعة 1) → نجاح الـ5 بلدات والطيبة البلد السادس (فقعة 2) → «حابين تكونوا جزء من نجاحنا في الطيبة.» (فقعة 3) → الميزات (فقعة 4). ممنوع تعكسيهن.
رابط الإنستغرام بفقعة 2 لازم ينزل كامل وكما هو حرف حرف — ممنوع تقصيره أو تغييره أو حذفه.
بعد آخر فقعة: توقفي. ممنوع CTA أو سعر بهالمرحلة.

# فقرة الطيبة (تلقائية بعد التعريف القصير — إذا بعرف ملان)
حابين تكونوا جزء من نجاحنا في الطيبة.

# ميزات الخدمة (تلقائية بعد فقرة الطيبة — إذا بعرف ملان)
وبعدها رسالة منفصلة قريبة من هيك:
والميزة عنا إنك بتتعامل مع شركة عربية من الألف للياء، من التركيب للدعم الفني. الخدمة عنا شغالة كمان الجمعة والسبت، وإذا صار عندك أي مشكلة هدفنا نحلها بنفس اليوم، وبحد أقصى ثاني يوم.

إذا بعرف: تعريف قصير → الطيبة → الميزات.
إذا ما بعرف: الترتيب الرباعي أعلاه فقط — ممنوع تكرري الطيبة/الميزات مرة ثانية.
بعد آخر فقعة: توقفي. ممنوع: شو رأيك؟ / حابب تعرف أكثر؟ / إذا عندك سؤال احكيلي / مهتم؟

## رفض / انزعاج — وقف فوري
إذا قال: حلي عني / خلص / مش مهتم / لا تبعثولي / ما بدي / اتركوني / مش حابب / لا تتواصلوا معي
→ ردي فقط: «تمام، يعطيك العافية.»
بعدها: ممنوع أي سؤال مبيعات، ممنوع طلب اسم/رقم، ممنوع create_malan_lead، وممنوع تكملة مسار الحملة.
TEXT;

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Closing question',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => str_replace("\n", "\r\n", $oldPrompt),
            'contacts_count' => 0,
        ]);

        $migration = require database_path('migrations/2026_08_26_140000_refresh_malan_campaign_closing_question_copy.php');
        $migration->up();
        $afterFirstPass = (string) $campaign->fresh()->system_prompt;

        $migration->up();
        $refreshed = (string) $campaign->fresh()->system_prompt;
        $this->assertSame($afterFirstPass, $refreshed, 'refresh must be idempotent');

        $this->assertStringContainsString('3) حابب تكون جزء من نجاحنا بالطيبة؟', $refreshed);
        $this->assertStringContainsString('# سؤال الإغلاق', $refreshed);
        $this->assertStringContainsString('# ميزات الخدمة — بس إذا سأل الزبون', $refreshed);
        $this->assertStringContainsString('بتحب نرجعلك بمكالمه بوقت ثاني؟ اذا اه، ايمتا بتكون فاضي؟', $refreshed);
        $this->assertStringContainsString('سؤال الإغلاق (فقعة 3)', $refreshed);
        $this->assertStringNotContainsString('4) والميزة عنا', $refreshed);
        $this->assertStringNotContainsString('# ميزات الخدمة (تلقائية', $refreshed);
        $this->assertStringNotContainsString('رفض / انزعاج — وقف فوري', $refreshed);
        $this->assertStringNotContainsString('كمّلي فقرة الطيبة ثم ميزات الخدمة', $refreshed);
    }

    public function test_soft_refusal_persuades_then_gives_chance_then_closes(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Refusal',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Refusal',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'REFUSE1'], 200),
        ]);

        $this->customerSays($conversation, 'مش حابب اسجل لا');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CALLBACK_OFFER_REPLY,
        ]);

        $conversation->forceFill([
            'metadata' => array_merge($conversation->fresh()->metadata ?? [], [
                'campaign_conversation_version' => 2,
            ]),
        ])->save();

        $this->customerSays($conversation, 'لا ما بدي');
        $this->runBurst($campaign, $conversation, 2);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::GIVE_CHANCE_REPLY,
        ]);

        $conversation->forceFill([
            'metadata' => array_merge($conversation->fresh()->metadata ?? [], [
                'campaign_conversation_version' => 3,
            ]),
        ])->save();

        $this->customerSays($conversation, 'لا');
        $this->runBurst($campaign, $conversation, 3);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CLOSE_REPLY,
        ]);
    }

    public function test_bezeq_loyalty_gets_persuasion_not_callback(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Bezeq',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Bezeq',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'BZ1'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::JOIN_QUESTION,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'لا بلزمش مرتاخ مع بيزك');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CALLBACK_OFFER_REPLY,
        ]);
    }

    public function test_no_fiber_at_home_explains_free_install_then_asks_to_join(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Fiber',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Fiber',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'FB1'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::FIBER_AT_HOME_QUESTION,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'لا لسه');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::NO_FIBER_INSTALL_REPLY,
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::NO_FIBER_PRICE_REPLY,
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::JOIN_QUESTION,
        ]);

        $assistantTexts = \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->pluck('message')
            ->implode("\n");
        $this->assertStringNotContainsString('واثقين', $assistantTexts);
        $this->assertStringNotContainsString('ينبسط', $assistantTexts);
    }

    public function test_has_fiber_at_home_asks_to_join(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Fiber yes',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Fiber yes',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'FB2'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::FIBER_AT_HOME_QUESTION,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'اه في عنا عادي');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::JOIN_QUESTION,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::NO_FIBER_INSTALL_REPLY,
        ]);
    }

    public function test_wanting_to_hear_more_sends_social_proof_once_not_in_intro(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Social proof',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Hear more',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'SP1'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::JOIN_QUESTION,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'بدي اسمع اول');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::SOCIAL_PROOF_REPLY,
        ]);
        $this->assertSame(1, \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('message', \App\Jobs\ProcessCampaignConversationJob::SOCIAL_PROOF_REPLY)
            ->count());
    }

    public function test_asking_about_other_customers_sends_instagram_after_persuasion(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Reviews',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Reviews',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        $memory = app(\App\Services\Malan\MalanConversationMemoryService::class);
        $this->assertTrue($memory->looksLikeAsksAboutCustomerReviews('طب شو راي باقي اللكوحوت بشكل عام مبسوطين يعني؟'));
        $this->assertStringContainsString('29 شيقل', \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'REV1'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'طب شو راي باقي اللكوحوت بشكل عام مبسوطين يعني؟');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::SOCIAL_PROOF_REPLY,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'إذا في عندك أسئلة أو استفسارات عن الخدمة أو أي شي تاني، خبرني.',
        ]);
    }

    public function test_lesh_la_after_join_question_starts_lead_handoff_not_persuasion(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Lesh la',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lesh la',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'YES1'], 200),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::JOIN_QUESTION,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, 'ليش لا');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::SAME_NUMBER_ASK_REPLY,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY,
        ]);
    }

    public function test_after_callback_offer_wanting_to_chat_does_not_repeat_the_question(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Chat first',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Sara',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        config(['services.openai.api_key' => 'test-key']);

        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'api.openai.com')) {
                return \Illuminate\Support\Facades\Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'تمام، خليني أحكيلك باختصار عن العرض: 29 أول 3 شهور وبعدها 149.',
                        ],
                    ]],
                ], 200);
            }

            return \Illuminate\Support\Facades\Http::response(['idMessage' => 'CHAT1'], 200);
        });

        $this->customerSays($conversation, 'مش حابب اسجل لا');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertSame(1, \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('message', \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY)
            ->count());
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CALLBACK_OFFER_REPLY,
        ]);

        $conversation->forceFill([
            'metadata' => array_merge($conversation->fresh()->metadata ?? [], [
                'campaign_conversation_version' => 2,
            ]),
        ])->save();

        $this->customerSays($conversation, 'بدي اسمع اول');
        $this->runBurst($campaign, $conversation, 2);

        $this->assertSame(1, \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('message', \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY)
            ->count());
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CALLBACK_OFFER_REPLY,
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CLOSE_REPLY,
        ]);
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'تمام، خليني أحكيلك باختصار عن العرض: 29 أول 3 شهور وبعدها 149.',
        ]);

        $conversation->forceFill([
            'metadata' => array_merge($conversation->fresh()->metadata ?? [], [
                'campaign_conversation_version' => 3,
            ]),
        ])->save();

        $this->customerSays($conversation, 'انا أسا فاضيه');
        $this->runBurst($campaign, $conversation, 3);

        $this->assertSame(1, \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('message', \App\Jobs\ProcessCampaignConversationJob::COMPETITOR_PERSUASION_REPLY)
            ->count());
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::CALLBACK_OFFER_REPLY,
        ]);
    }

    public function test_follow_up_nudge_is_sent_only_when_closing_question_stays_unanswered(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Nudge',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Nudge',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500088899@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500088899@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'NUDGE1'], 200),
        ]);

        $trigger = $this->customerSays($conversation, 'لا ما بعرفكم');
        $asked = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => 'حابب تكون جزء من نجاحنا بالطيبة؟',
            'delivery_status' => 'sent',
            'metadata' => ['trigger_user_message_id' => $trigger->id],
        ]);
        $asked->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->assertTrue(\App\Jobs\SendCampaignFollowUpNudgeJob::textAsksClosingQuestion((string) $asked->message));

        $job = new \App\Jobs\SendCampaignFollowUpNudgeJob(
            (int) $campaign->id,
            (int) $conversation->id,
            '972500088899@c.us',
            (int) $trigger->id,
            (int) $asked->id,
            1,
        );
        $job->handle(app(\App\Services\AiChatbot\ChatbotGreenApiService::class));

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\SendCampaignFollowUpNudgeJob::NUDGE_TEXT,
            'delivery_status' => 'sent',
        ]);

        $meta = $conversation->fresh()->metadata ?? [];
        $this->assertSame((int) $asked->id, (int) ($meta['campaign_followup_nudge_for_message_id'] ?? 0));
        $this->assertNotEmpty($meta['campaign_followup_nudge_sent_at'] ?? null);

        // Never nudge twice about the same question.
        $job->handle(app(\App\Services\AiChatbot\ChatbotGreenApiService::class));
        $this->assertSame(1, \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message', \App\Jobs\SendCampaignFollowUpNudgeJob::NUDGE_TEXT)
            ->count());

        // A customer answer cancels the nudge for another conversation.
        $other = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Answered',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500099900@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'campaign_conversation_version' => 1,
            ],
        ]);
        $otherTrigger = $this->customerSays($other, 'لا ما بعرفكم');
        $otherAsked = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $other->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => 'حابب تكون جزء من نجاحنا بالطيبة؟',
            'delivery_status' => 'sent',
            'metadata' => ['trigger_user_message_id' => $otherTrigger->id],
        ]);
        $otherAsked->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $this->customerSays($other, 'اه اكيد');

        (new \App\Jobs\SendCampaignFollowUpNudgeJob(
            (int) $campaign->id,
            (int) $other->id,
            '972500099900@c.us',
            (int) $otherTrigger->id,
            (int) $otherAsked->id,
            1,
        ))->handle(app(\App\Services\AiChatbot\ChatbotGreenApiService::class));

        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $other->id,
            'message' => \App\Jobs\SendCampaignFollowUpNudgeJob::NUDGE_TEXT,
        ]);
    }

    public function test_silence_after_follow_up_nudge_counts_as_no_response(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Nudge insights',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Ghosted',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500055566@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'name' => 'Ghost',
            'phone' => '0500055566',
            'phone_normalized' => '0500055566',
            'chat_id' => '972500055566@c.us',
            'status' => MalanCampaignContact::STATUS_RESPONDED,
            'message_sent_at' => now()->subHour(),
            'responded_at' => now()->subMinutes(40),
        ]);

        $this->customerSays($conversation, 'اه بعرفكم');
        $nudge = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_SYSTEM,
            'message_type' => 'text',
            'message' => \App\Jobs\SendCampaignFollowUpNudgeJob::NUDGE_TEXT,
            'delivery_status' => 'sent',
        ]);

        $insights = app(\App\Services\Malan\Campaigns\CampaignInsightService::class);

        $conversation->forceFill(['metadata' => array_merge($conversation->metadata ?? [], [
            'campaign_followup_nudge_message_id' => (int) $nudge->id,
            'campaign_followup_nudge_sent_at' => now()->subSeconds(30)->toIso8601String(),
        ])])->save();

        // Still inside the reply window — keep them as engaged.
        $this->assertSame(1, $insights->summarize($campaign)['engaged']);

        $conversation->forceFill(['metadata' => array_merge($conversation->fresh()->metadata ?? [], [
            'campaign_followup_nudge_sent_at' => now()->subMinutes(10)->toIso8601String(),
        ])])->save();

        $summary = $insights->summarize($campaign);
        $this->assertSame(1, $summary['no_response']);
        $this->assertSame(0, $summary['engaged']);

        // Answering the nudge puts them back with the responders.
        $this->customerSays($conversation, 'اه بعدين');
        $summary = $insights->summarize($campaign);
        $this->assertSame(0, $summary['no_response']);
        $this->assertSame(1, $summary['engaged']);
    }

    private function customerSays(ChatbotConversation $conversation, string $text): \App\Models\AiChatbot\ChatbotMessage
    {
        return \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => $text,
        ]);
    }

    private function runBurst(MalanCampaign $campaign, ChatbotConversation $conversation, int $version): void
    {
        $job = new \App\Jobs\ProcessCampaignConversationJob(
            (int) $campaign->id,
            (int) $conversation->id,
            $version,
            '972500077788@c.us',
        );

        $job->handle(
            app(\App\Services\AiChatbot\AiChatbotService::class),
            app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
            app(\App\Services\Malan\Campaigns\CampaignConversationBurstService::class),
            app(\App\Services\Malan\MalanConversationContextService::class),
        );
    }

    public function test_campaign_listening_window_and_burst_versioning(): void
    {
        $debounce = \App\Services\Malan\Campaigns\CampaignListeningWindow::secondsUntilProcess(now());
        $this->assertGreaterThanOrEqual(1, $debounce);
        $this->assertLessThanOrEqual(4, $debounce);

        $capped = \App\Services\Malan\Campaigns\CampaignListeningWindow::secondsUntilProcess(
            now()->subSeconds(9)
        );
        $this->assertLessThanOrEqual(2, $capped);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Burst',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Burst',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500011122@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true, 'campaign_conversation_version' => 0],
        ]);

        $pending = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => 'old pending',
            'delivery_status' => 'pending',
            'metadata' => [
                'campaign_delivery' => 'queued',
                'campaign_response_state' => 'pending_send',
                'campaign_generated_for_version' => 1,
            ],
        ]);

        $burst = app(\App\Services\Malan\Campaigns\CampaignConversationBurstService::class);
        $reg1 = $burst->registerInboundMessage($conversation->fresh());
        $this->assertSame(1, $reg1['version']);
        $pending->refresh();
        $this->assertSame('stale', $pending->metadata['campaign_response_state'] ?? null);

        $msg1 = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => 'انا مع بيزك',
            'metadata' => ['campaign_burst_unprocessed' => true],
        ]);
        $msg2 = \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => 'بس بدفع كثير',
            'metadata' => ['campaign_burst_unprocessed' => true],
        ]);

        $collected = $burst->collectUnprocessedBurst($conversation->fresh());
        $this->assertCount(2, $collected);
        $ephemeral = $burst->buildBurstEphemeralPrompt($collected);
        $this->assertStringContainsString('انا مع بيزك', $ephemeral);
        $this->assertStringContainsString('بس بدفع كثير', $ephemeral);
        $this->assertStringContainsString('CAMPAIGN_BURST', $ephemeral);

        $reg2 = $burst->registerInboundMessage($conversation->fresh());
        $this->assertSame(2, $reg2['version']);
        $this->assertNotSame($msg1->id, $msg2->id);
    }

    public function test_campaign_human_delay_buckets_and_bubble_split(): void
    {
        $nameAsk = \App\Services\Malan\Campaigns\CampaignHumanDelay::secondsForText('تمام، اعطيني اسمك الكامل بس.');
        $this->assertGreaterThanOrEqual(0.35, $nameAsk);
        $this->assertLessThanOrEqual(0.8, $nameAsk);

        $short = \App\Services\Malan\Campaigns\CampaignHumanDelay::secondsForText('يا هلا');
        $this->assertGreaterThanOrEqual(0.35, $short);
        $this->assertLessThanOrEqual(1.0, $short);

        $long = \App\Services\Malan\Campaigns\CampaignHumanDelay::secondsForText(str_repeat('م', 360));
        $this->assertGreaterThanOrEqual(5, $long);
        $this->assertLessThanOrEqual(12, $long);

        $veryLong = \App\Services\Malan\Campaigns\CampaignHumanDelay::secondsForText(str_repeat('م', 500));
        $this->assertGreaterThanOrEqual(7, $veryLong);
        $this->assertLessThanOrEqual(12, $veryLong);
        $this->assertGreaterThanOrEqual($short, $long);

        $bubbles = \App\Services\Malan\Campaigns\CampaignHumanDelay::splitBubbles("سطر واحد\n\nسطر اثنين");
        $this->assertSame(['سطر واحد', 'سطر اثنين'], $bubbles);

        $stripped = \App\Services\Malan\Campaigns\CampaignHumanDelay::splitBubbles("① انا من ملان\n\n② مش رح اوخذ من وقتك كثير");
        $this->assertSame(['انا من ملان', 'مش رح اوخذ من وقتك كثير'], $stripped);

        $green = app(\App\Services\AiChatbot\ChatbotGreenApiService::class);
        $this->assertSame(1000, $green->typingTimeForText('تمام، اعطيني اسمك الكامل بس.'));
    }

    public function test_campaign_webhook_listens_without_immediate_ai_call(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Listen',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Listen',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500022233@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500022233@c.us',
                'campaign_conversation_version' => 0,
            ],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Test',
            'phone' => '0500022233',
            'phone_normalized' => '0500022233',
            'chat_id' => '972500022233@c.us',
            'conversation_id' => $conversation->id,
            'status' => MalanCampaignContact::STATUS_SENT,
        ]);

        $openaiHits = 0;
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$openaiHits) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $openaiHits++;

                return \Illuminate\Support\Facades\Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'reply'],
                    ]],
                ], 200);
            }

            return \Illuminate\Support\Facades\Http::response(['ok' => true], 200);
        });

        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\ProcessCampaignConversationJob::class,
        ]);

        $incoming = new \App\Services\AiChatbot\GreenApiIncomingMessage(
            type: 'textMessage',
            chatId: '972500022233@c.us',
            messageId: 'MSG-LISTEN-1',
            text: 'اه بعرفكم',
            caption: null,
            downloadUrl: null,
            mimeType: null,
            fileName: null,
        );

        $result = app(\App\Services\Malan\Campaigns\MalanCampaignWebhookService::class)
            ->handleParsedInbound($campaign, $incoming);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($result['listening'] ?? false);
        $this->assertSame(0, $openaiHits);
        $this->assertSame(1, (int) ($result['conversation_version'] ?? 0));
        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'message' => 'اه بعرفكم',
        ]);
        $this->assertDatabaseMissing('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'reply',
        ]);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessCampaignConversationJob::class);
    }

    public function test_campaign_process_requeues_when_lock_busy(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Lock',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lock',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500044455@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'campaign_conversation_version' => 3,
                'campaign_burst_open' => true,
                'whatsapp_chat_id' => '972500044455@c.us',
            ],
        ]);

        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\ProcessCampaignConversationJob::class,
        ]);

        $lock = \Illuminate\Support\Facades\Cache::lock('campaign-burst-process-'.$conversation->id, 30);
        $this->assertTrue($lock->get());

        try {
            $job = new \App\Jobs\ProcessCampaignConversationJob(
                (int) $campaign->id,
                (int) $conversation->id,
                3,
                '972500044455@c.us',
            );
            $job->handle(
                app(\App\Services\AiChatbot\AiChatbotService::class),
                app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
                app(\App\Services\Malan\Campaigns\CampaignConversationBurstService::class),
                app(\App\Services\Malan\MalanConversationContextService::class),
            );
        } finally {
            optional($lock)->release();
        }

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessCampaignConversationJob::class);
    }

    public function test_campaign_greeting_uses_sales_mode_not_central_intake_in_openai_prompt(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Sales mode',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Fresh lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500011122@c.us',
            'contact_phone' => '972500011122',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        config(['services.openai.api_key' => 'test-key']);

        $capturedSystem = null;
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$capturedSystem) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $payload = $request->data();
                foreach ($payload['messages'] ?? [] as $msg) {
                    if (($msg['role'] ?? null) === 'system') {
                        $capturedSystem = (string) ($msg['content'] ?? '');
                    }
                }

                return \Illuminate\Support\Facades\Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'أهلين 👋 سمعت قبل عن شركة ملان؟',
                        ],
                    ]],
                ], 200);
            }

            return \Illuminate\Support\Facades\Http::response(['ok' => true], 200);
        });

        app(\App\Services\AiChatbot\AiChatbotService::class)->sendMessage(
            $user,
            $instance,
            'הלא',
            (int) $conversation->id,
            [
                'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
                'campaign_lead_bot' => true,
            ],
        );

        $this->assertNotNull($capturedSystem);
        $this->assertStringContainsString('campaign_sales', $capturedSystem);
        $this->assertStringContainsString('Do NOT ask for full name/phone/city yet', $capturedSystem);
        $this->assertStringContainsString('NO dash before', $capturedSystem);
        $this->assertStringNotContainsString('MODE=new_signup', $capturedSystem);
        $this->assertStringNotContainsString('Collect full_name + phone + city only', $capturedSystem);
        $this->assertStringNotContainsString('You are Sally for Malan.', $capturedSystem);

        $context = app(\App\Services\Malan\MalanConversationContextService::class)->getActive($conversation->fresh());
        $this->assertTrue(app(\App\Services\Malan\MalanConversationContextService::class)->isCampaignSalesMode($context));
        $this->assertFalse(app(\App\Services\Malan\MalanConversationContextService::class)->isNewSignupMode($context));

        $tools = app(\App\Services\AiChatbot\Tools\ChatbotToolDefinitions::class)
            ->forInstance($instance, 'whatsapp', false, $conversation);
        $this->assertCount(1, $tools);
        $this->assertStringContainsString('عهاذ الرقم', $tools[0]['function']['description']);
        $this->assertStringContainsString('بنفع', $tools[0]['function']['description']);
    }

    public function test_campaign_conversion_intent_switches_to_lead_collection_mode(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Convert',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Convert',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500033344@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $memory = app(\App\Services\Malan\MalanConversationMemoryService::class);
        $ctx = $memory->observeUserMessage($conversation, $instance, 'הלא');
        $this->assertTrue(app(\App\Services\Malan\MalanConversationContextService::class)->isCampaignSalesMode($ctx));

        $ctx = $memory->observeUserMessage($conversation, $instance, 'بدي اشترك');
        $this->assertTrue(app(\App\Services\Malan\MalanConversationContextService::class)->isCampaignLeadCollectionMode($ctx));
    }

    public function test_same_number_affirmative_with_punctuation_locks_contact_even_in_sales_mode(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Same Number',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Same Number',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500044455@c.us',
            'contact_phone' => '972500044455',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $contextService = app(\App\Services\Malan\MalanConversationContextService::class);
        $contextService->beginCampaignSales($conversation, $instance);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message' => 'تمام، إذا حابب تنضم النا، بقدر أخلي الصبيه تتواصل معك كمان شوي تشرحلك أكثر. بدك أخليها تتواصل معك عهاد الرقم؟',
        ]);

        $memory = app(\App\Services\Malan\MalanConversationMemoryService::class);
        $this->assertTrue($memory->looksLikeSameNumberAffirmative('بنفع !!'));

        $ctx = $memory->observeUserMessage($conversation, $instance, 'بنفع !!');
        $this->assertTrue($contextService->isCampaignLeadCollectionMode($ctx));
        $this->assertTrue($contextService->campaignContactOnCurrentNumber($ctx));
        $this->assertTrue($memory->looksLikeCampaignPersonName('محمد أحمد'));
        $this->assertFalse($memory->looksLikeCampaignPersonName('بنفع !!'));
        $this->assertFalse($memory->looksLikeCampaignPersonName('الله يعافيكي اهلين'));
        $this->assertFalse($memory->looksLikeCampaignPersonName('الله يعطيكي العافيه'));
        $this->assertFalse($memory->looksLikeCampaignPersonName('0533046830'));
        $this->assertSame('0533046830', $memory->extractWrittenCampaignPhone('0533046830'));
        $this->assertSame('0533046830', $memory->extractWrittenCampaignPhone('تواصل معي على 053-304-6830'));
        $this->assertSame('16:00', $memory->extractPreferredCallTime('اتصلوا الساعة 16:00 pm'));
        $this->assertSame('16:00', $memory->extractPreferredCallTime('بدي مكالمه الساعة 4 المسا'));
        $this->assertTrue($memory->looksLikeWantsLaterCall('رجعوني بعدين مش هلق'));
    }

    public function test_alternate_phone_after_same_number_ask_is_not_used_as_the_lead_name(): void
    {
        config([
            'malan.api.key' => 'test-malan-key',
            'malan.leads.campaign_source_id' => 66,
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Alt phone',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Subhi',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'contact_phone' => '972500077788',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'createLead')) {
                return Http::response([
                    'result' => true,
                    'data' => ['lead_id' => 42, 'leads_sources_id' => 66, 'statuses_id' => 4],
                ], 201);
            }

            return Http::response(['idMessage' => 'ALT1'], 200);
        });

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::SAME_NUMBER_ASK_REPLY,
            'delivery_status' => 'sent',
        ]);

        $this->customerSays($conversation, '0533046830');
        $this->runBurst($campaign, $conversation, 1);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => \App\Jobs\ProcessCampaignConversationJob::ASK_NAME_REPLY,
        ]);

        $contextService = app(\App\Services\Malan\MalanConversationContextService::class);
        $context = $contextService->getActive($conversation);
        $this->assertSame('0533046830', $contextService->campaignLeadPhone($context));
        $this->assertFalse($contextService->campaignContactOnCurrentNumber($context));

        Http::assertNotSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), 'createLead');
        });

        $conversation->forceFill([
            'metadata' => array_merge($conversation->fresh()->metadata ?? [], [
                'campaign_conversation_version' => 2,
            ]),
        ])->save();

        $this->customerSays($conversation, 'صبحي');
        $this->runBurst($campaign, $conversation, 2);

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'message' => 'تمام صبحي، رح تتواصل معك الصبيه كمان شوي 👍',
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_ends_with($request->url(), '/apiClient/createLead')
                && $request['full_name'] === 'صبحي'
                && $request['phone'] === '0533046830';
        });
    }

    public function test_campaign_outbound_rewrites_mixed_hebrew_product_terms(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Hebrew mix',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Hebrew',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
                'campaign_conversation_version' => 1,
            ],
        ]);

        config(['services.openai.api_key' => 'test-key']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'api.openai.com')) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'أول 200 زبون بيوخذو مגדיل טווח مجاني، واحنا أحسن من بזק.',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['idMessage' => 'HE1'], 200);
        });

        $this->customerSays($conversation, 'شو السعر؟');
        $this->runBurst($campaign, $conversation, 1);

        $reply = \App\Models\AiChatbot\ChatbotMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->value('message');

        $this->assertIsString($reply);
        $this->assertStringContainsString('מגדיל טווח', $reply);
        $this->assertStringContainsString('בזק', $reply);
        $this->assertStringNotContainsString('مגדיל', $reply);
        $this->assertStringNotContainsString('بזק', $reply);
    }

    public function test_trigger_resend_clears_conversation_history_and_lead_flags(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Reset',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Reset',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500066677@c.us',
            'contact_phone' => '972500066677',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'campaign_conversation_version' => 3,
            ],
        ]);

        $contextService = app(\App\Services\Malan\MalanConversationContextService::class);
        $contextService->markContactOnCurrentNumber($conversation, $instance);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message' => 'صبحي الفصاعنه',
        ]);

        $contextService->resetCampaignConversationForResend($conversation, $instance);

        $this->assertSame(0, \App\Models\AiChatbot\ChatbotMessage::query()->where('conversation_id', $conversation->id)->count());
        $ctx = $contextService->getActive($conversation);
        $this->assertTrue($contextService->isCampaignSalesMode($ctx));
        $this->assertFalse($contextService->campaignContactOnCurrentNumber($ctx));
        $this->assertSame(4, (int) (($conversation->fresh()->metadata['campaign_conversation_version'] ?? 0)));
    }

    public function test_campaign_chat_bot_mode_and_poll_endpoints(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Live',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Live Chat',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500099988@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'contact_name' => 'صبحي',
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.campaigns.conversations.bot-mode', [$instance, $campaign, $conversation]), [
                'bot_mode' => ChatbotConversation::BOT_MODE_PAUSED,
            ])
            ->assertOk()
            ->assertJsonPath('conversation.bot_mode', ChatbotConversation::BOT_MODE_PAUSED);

        $this->assertSame(ChatbotConversation::BOT_MODE_PAUSED, $conversation->fresh()->bot_mode);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.campaigns.conversations.poll', [$instance, $campaign]))
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversation->id);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.campaigns.analytics', [$instance, $campaign]))
            ->assertOk()
            ->assertJsonStructure(['analytics' => [
                'leads', 'responded', 'messages', 'contacts', 'no_response', 'rejected', 'engaged',
            ]]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign, 'conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('chat-bot-toggle', false)
            ->assertSee('workspace-chat.js', false);
    }

    public function test_campaign_test_page_is_reachable(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Sandbox',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns.test.page', [$instance, $campaign]))
            ->assertOk()
            ->assertSee('data-url="'.route('ai-chatbot.workspace.campaigns.test', [$instance, $campaign]).'"', false)
            ->assertSee('id="test-form"', false)
            ->assertSee('id="test-send"', false)
            ->assertSee('workspace-test-chat.js', false)
            ->assertSee('WorkspaceTestChat.mount', false);
    }

    public function test_campaign_test_sandbox_sends_message(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'اهلين، كيف فيني اساعدك؟']]],
            ], 200),
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Taybee sandbox',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.campaigns.test', [$instance, $campaign]), [
                'message' => 'مرحبا اهلين فيكي',
                'channel' => 'test',
                'reset' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('simulation', true)
            ->assertJsonPath('campaign_id', $campaign->id)
            ->assertJsonPath('assistant_response', 'اهلين، كيف فيني اساعدك؟');

        $this->assertTrue(
            ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->where('campaign_id', $campaign->id)
                ->where('channel', ChatbotConversation::CHANNEL_TEST)
                ->exists()
        );
    }

    public function test_trigger_on_completed_campaign_resends_opening_messages(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Resend me',
            'status' => MalanCampaign::STATUS_COMPLETED,
            'is_active' => true,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello again',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
            'messages_triggered_count' => 1,
            'completed_at' => now()->subDay(),
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Sara',
            'phone' => '0524060606',
            'phone_normalized' => '0524060606',
            'chat_id' => '972524060606@c.us',
            'status' => MalanCampaignContact::STATUS_RESPONDED,
            'message_sent_at' => now()->subDay(),
            'responded_at' => now()->subDay(),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'RESEND1'], 200),
        ]);

        $contactId = (int) MalanCampaignContact::query()->where('campaign_id', $campaign->id)->value('id');

        $this->actingAs($user)
            ->post(route('ai-chatbot.workspace.campaigns.start', [$instance, $campaign]), [
                'contact_ids' => [$contactId],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $contact = MalanCampaignContact::query()->where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertNotNull($contact->message_sent_at);
        $this->assertNull($contact->last_error);
    }

    public function test_campaign_contacts_json_includes_names(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Picker',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 2,
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Ahmad Ali',
            'phone' => '0501111111',
            'phone_normalized' => '0501111111',
            'city' => 'Haifa',
            'status' => MalanCampaignContact::STATUS_PENDING,
        ]);
        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => '',
            'phone' => '0502222222',
            'phone_normalized' => '0502222222',
            'status' => MalanCampaignContact::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.campaigns.contacts', [$instance, $campaign]))
            ->assertOk()
            ->assertJsonPath('contacts.0.name', 'Ahmad Ali')
            ->assertJsonPath('contacts.0.phone', '0501111111')
            ->assertJsonPath('contacts.0.city', 'Haifa')
            ->assertJsonPath('contacts.1.name', '')
            ->assertJsonCount(2, 'contacts');
    }

    public function test_campaign_can_add_custom_contact_from_trigger(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Custom add',
            'status' => MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.campaigns.contacts.store', [$instance, $campaign]), [
                'phone' => '0524060606',
                'name' => 'Yamen',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', true)
            ->assertJsonPath('contact.name', 'Yamen')
            ->assertJsonPath('contact.phone', '0524060606');

        $this->assertDatabaseHas('malan_campaign_contacts', [
            'campaign_id' => $campaign->id,
            'name' => 'Yamen',
            'phone_normalized' => '0524060606',
            'chat_id' => '972524060606@c.us',
        ]);

        $this->assertSame(1, (int) $campaign->fresh()?->contacts_count);

        // Duplicate phone returns existing row (selected again by UI).
        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.campaigns.contacts.store', [$instance, $campaign]), [
                'phone' => '972524060606',
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('contact.phone', '0524060606');

        $this->assertSame(1, MalanCampaignContact::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_campaign_insights_classify_and_export_phones(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Insights',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 3,
        ]);

        $leadConversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972501111111@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);
        $rejectConversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Reject',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972502222222@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'conversation_id' => $leadConversation->id,
            'name' => 'Lead Person',
            'phone' => '0501111111',
            'phone_normalized' => '0501111111',
            'chat_id' => '972501111111@c.us',
            'status' => MalanCampaignContact::STATUS_LEAD_CREATED,
            'message_sent_at' => now()->subHour(),
            'responded_at' => now()->subMinutes(50),
            'lead_created_at' => now()->subMinutes(40),
        ]);
        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Silent',
            'phone' => '0503333333',
            'phone_normalized' => '0503333333',
            'chat_id' => '972503333333@c.us',
            'status' => MalanCampaignContact::STATUS_SENT,
            'message_sent_at' => now()->subHour(),
            'responded_at' => null,
        ]);
        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'conversation_id' => $rejectConversation->id,
            'name' => 'Rejector',
            'phone' => '0502222222',
            'phone_normalized' => '0502222222',
            'chat_id' => '972502222222@c.us',
            'status' => MalanCampaignContact::STATUS_RESPONDED,
            'message_sent_at' => now()->subHour(),
            'responded_at' => now()->subMinutes(30),
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $rejectConversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => 'ما بدي شكرا، بيزك أحسن',
        ]);

        $summary = app(\App\Services\Malan\Campaigns\CampaignInsightService::class)->summarize($campaign);
        $this->assertSame(1, $summary['leads']);
        $this->assertSame(1, $summary['no_response']);
        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(0, $summary['engaged']);

        $export = $this->actingAs($user)
            ->withSession(['locale' => 'he'])
            ->get(route('ai-chatbot.workspace.campaigns.analytics.export', [$instance, $campaign, 'rejected']));
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('0502222222', $csv);
        $this->assertStringContainsString('Rejector', $csv);
        $this->assertStringContainsString('טלפון', $csv);
        $this->assertStringContainsString('שם', $csv);
        $this->assertStringContainsString('דחייה / שלילי', $csv);

        $exportEn = $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get(route('ai-chatbot.workspace.campaigns.analytics.export', [$instance, $campaign, 'rejected']));
        $csvEn = $exportEn->streamedContent();
        $this->assertStringContainsString('Phone', $csvEn);
        $this->assertStringContainsString('Rejected / negative', $csvEn);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign]))
            ->assertOk()
            ->assertSee(__('chatbot.workspace.campaigns.stat_rejected'), false)
            ->assertSee(__('chatbot.workspace.campaigns.download_phones'), false)
            ->assertSee('data-insight-filter', false);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns.show', [$instance, $campaign, 'conversation' => $rejectConversation->id]))
            ->assertOk()
            ->assertSee('reply-form', false)
            ->assertSee('id="reply-input"', false);

        $this->actingAs($user)
            ->getJson(route('ai-chatbot.workspace.campaigns.conversations.poll', [$instance, $campaign]).'?insight=rejected')
            ->assertOk()
            ->assertJsonPath('insight', 'rejected')
            ->assertJsonPath('conversations.0.id', $rejectConversation->id)
            ->assertJsonCount(1, 'conversations');

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['idMessage' => 'STAFF1'], 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.workspace.campaigns.conversations.reply', [$instance, $campaign, $rejectConversation]), [
                'message' => 'تمام، شكرا على وقتك',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('delivery.channel', 'whatsapp');

        $this->assertDatabaseHas('ai_chatbot_messages', [
            'conversation_id' => $rejectConversation->id,
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_HUMAN,
            'message' => 'تمام، شكرا على وقتك',
        ]);
    }

    public function test_phone_dots_silence_only_that_campaign_conversation(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Dot silence',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'greenapi_webhook_token' => 'campaign-dot-token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 2,
        ]);

        $silenced = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Silenced',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500011100@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500011100@c.us',
                'campaign_conversation_version' => 2,
            ],
        ]);
        $other = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Other',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500011199@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500011199@c.us',
            ],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Abed',
            'phone' => '0500011100',
            'phone_normalized' => '0500011100',
            'chat_id' => '972500011100@c.us',
            'conversation_id' => $silenced->id,
            'status' => MalanCampaignContact::STATUS_SENT,
            'message_sent_at' => now(),
        ]);

        $pending = $silenced->messages()->create([
            'role' => 'assistant',
            'sender_type' => 'ai',
            'message_type' => 'text',
            'message' => 'pending reply that must not send',
            'delivery_status' => 'pending',
            'metadata' => [
                'campaign_response_state' => \App\Services\Malan\Campaigns\CampaignConversationBurstService::STATE_PENDING_SEND,
            ],
        ]);

        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\ProcessCampaignConversationJob::class,
        ]);

        $this->postJson(route('ai-chatbot.greenapi.campaign-webhook', ['token' => 'campaign-dot-token']), [
            'typeWebhook' => 'outgoingMessageReceived',
            'senderData' => ['chatId' => '972500011100@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'campaign-dot-stop-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('phone_dot_toggle', true)
            ->assertJsonPath('bot_mode', ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER)
            ->assertJsonPath('bot_stopped', true)
            ->assertJsonPath('campaign_id', $campaign->id);

        $this->assertSame(ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER, $silenced->fresh()->bot_mode);
        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $other->fresh()->bot_mode);
        $this->assertSame('failed', $pending->fresh()->delivery_status);
        $this->assertSame(
            \App\Services\Malan\Campaigns\CampaignConversationBurstService::STATE_CANCELLED,
            $pending->fresh()->metadata['campaign_delivery'] ?? null,
        );

        $this->postJson(route('ai-chatbot.greenapi.campaign-webhook', ['token' => 'campaign-dot-token']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972500011100@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => 'اه وينك'],
                'idMessage' => 'after-dot-inbound',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('stored_without_reply', true);

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\ProcessCampaignConversationJob::class);
        $this->assertSame(ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER, $silenced->fresh()->bot_mode);
    }

    public function test_shared_webhook_phone_dots_silence_campaign_conversation_not_inbox(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $instance->forceFill([
            'is_active' => true,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance99/sendMessage/secret',
            'greenapi_webhook_token' => 'shared-dot-token',
        ])->save();

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Shared dots',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => $instance->greenapi_url,
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Lead',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500077788@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500077788@c.us',
            ],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Lead',
            'phone' => '0500077788',
            'phone_normalized' => '0500077788',
            'chat_id' => '972500077788@c.us',
            'conversation_id' => $conversation->id,
            'status' => MalanCampaignContact::STATUS_SENT,
            'message_sent_at' => now(),
        ]);

        $this->postJson(route('ai-chatbot.greenapi.webhook', ['token' => 'shared-dot-token']), [
            'typeWebhook' => 'outgoingMessageReceived',
            'senderData' => ['chatId' => '972500077788@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'shared-dot-stop-1',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('phone_dot_toggle', true)
            ->assertJsonPath('bot_mode', ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER)
            ->assertJsonPath('campaign_id', $campaign->id);

        $this->assertSame(ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER, $conversation->fresh()->bot_mode);
        $this->assertSame(1, ChatbotConversation::query()->where('instance_id', $instance->id)->count());
    }

    public function test_customer_incoming_dots_do_not_silence_campaign_bot(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Customer dots',
            'status' => MalanCampaign::STATUS_RUNNING,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'greenapi_webhook_token' => 'customer-dot-token',
            'opening_message' => 'hello',
            'system_prompt' => MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 1,
        ]);

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'title' => 'Customer',
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => 'campaign:'.$campaign->id.':972500066677@c.us',
            'bot_mode' => ChatbotConversation::BOT_MODE_ACTIVE,
            'metadata' => [
                'campaign_lead_bot' => true,
                'whatsapp_chat_id' => '972500066677@c.us',
            ],
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Customer',
            'phone' => '0500066677',
            'phone_normalized' => '0500066677',
            'chat_id' => '972500066677@c.us',
            'conversation_id' => $conversation->id,
            'status' => MalanCampaignContact::STATUS_SENT,
            'message_sent_at' => now(),
        ]);

        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\ProcessCampaignConversationJob::class,
        ]);

        $this->postJson(route('ai-chatbot.greenapi.campaign-webhook', ['token' => 'customer-dot-token']), [
            'typeWebhook' => 'incomingMessageReceived',
            'timestamp' => now()->timestamp,
            'senderData' => ['chatId' => '972500066677@c.us'],
            'messageData' => [
                'textMessageData' => ['textMessage' => '..'],
                'idMessage' => 'customer-campaign-dots',
            ],
        ])
            ->assertOk()
            ->assertJsonMissing(['phone_dot_toggle' => true]);

        $this->assertSame(ChatbotConversation::BOT_MODE_ACTIVE, $conversation->fresh()->bot_mode);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessCampaignConversationJob::class);
    }
}