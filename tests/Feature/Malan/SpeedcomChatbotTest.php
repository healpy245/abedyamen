<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanCampaign;
use App\Models\User;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Support\InternetCompanyProfile;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpeedcomChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SpeedcomChatbotInstanceSeeder::class);
    }

    public function test_seeder_creates_speedcom_instance_with_empty_greenapi(): void
    {
        $instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->first();

        $this->assertNotNull($instance);
        $this->assertSame(InternetCompanyProfile::SLUG_SPEEDCOM, $instance->integration_type);
        $this->assertTrue($instance->hasMalanIntegration());
        $this->assertTrue($instance->companyProfile()->isSpeedcom());
        $this->assertSame('رهط', $instance->campaignDefaultCity());
        $this->assertSame('', trim((string) $instance->greenapi_url));
        $prompt = (string) $instance->system_prompt;
        $this->assertStringContainsString('ممدوح', $prompt);
        $this->assertStringContainsString('سبيدكوم', $prompt);
        $this->assertStringContainsString('رهط', $prompt);
        $this->assertStringContainsString('5000', $prompt);
        $this->assertStringContainsString('299', $prompt);
        $this->assertStringContainsString('99 شيكل', $prompt);
        $this->assertStringContainsString('159', $prompt);
        $this->assertStringContainsString('يا هلا، تفضل كيف بقدر أساعدك؟', $prompt);
        $this->assertStringContainsString('بدك اشتراك جديد، ولا عندك مشكلة بالنّت؟', $prompt);
        $this->assertStringContainsString('لهجة رهط', $prompt);
        $this->assertStringContainsString('129 شيكل عالشهر', $prompt);
        $this->assertStringContainsString('90 بالمية', $prompt);
        $this->assertStringContainsString('חבילת الـ1000', $prompt);
        $this->assertStringNotContainsString('السلام عليكم، معك ممدوح', $prompt);
        $this->assertStringContainsString('الراوتر مطفي ولا ضاوي؟', $prompt);
        $this->assertStringContainsString('أحمر ضعيف', $prompt);
        $this->assertStringContainsString('مقطوع بالمرة', $prompt);
        $this->assertStringNotContainsString('سالي', $prompt);
        $this->assertStringNotContainsString('اللقية', $prompt);
        $this->assertStringNotContainsString('كفرقاسم', $prompt);
        $this->assertStringNotContainsString('ملان انترنت', $prompt);
        $this->assertSame('Mamdouh — Speedcom Internet CRM', $instance->integration_settings['label'] ?? null);
    }

    public function test_reseed_renames_legacy_sally_without_clearing_greenapi(): void
    {
        $instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->firstOrFail();
        $instance->forceFill([
            'name' => SpeedcomChatbotInstanceSeeder::LEGACY_INSTANCE_NAME,
            'greenapi_url' => 'https://green.example/speedcom',
        ])->save();

        $this->seed(SpeedcomChatbotInstanceSeeder::class);

        $instance->refresh();
        $this->assertSame(SpeedcomChatbotInstanceSeeder::INSTANCE_NAME, $instance->name);
        $this->assertSame('https://green.example/speedcom', $instance->greenapi_url);
        $this->assertSame(1, ChatbotInstance::query()->where('integration_type', 'speedcom')->count());
    }

    public function test_speedcom_workspace_has_campaigns_and_settings(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.campaigns', $instance))
            ->assertOk()
            ->assertSee(__('chatbot.workspace.nav_campaigns'), false);

        $this->actingAs($user)
            ->get(route('ai-chatbot.workspace.settings', $instance))
            ->assertOk()
            ->assertSee('greenapi_url', false);
    }

    public function test_speedcom_campaign_prompt_and_lead_city_use_rahat(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->firstOrFail();

        $prompt = MalanCampaign::defaultLeadSystemPrompt($instance);
        $this->assertStringContainsString('ممدوح', $prompt);
        $this->assertStringContainsString('سبيدكوم', $prompt);
        $this->assertStringContainsString('رهط', $prompt);
        $this->assertStringContainsString('5000', $prompt);
        $this->assertStringContainsString('299', $prompt);
        $this->assertStringContainsString('99 شيكل', $prompt);
        $this->assertStringNotContainsString('الطيبة', $prompt);
        $this->assertStringNotContainsString('ملان', $prompt);
        $this->assertStringNotContainsString('سالي', $prompt);
        $this->assertStringNotContainsString('كفر قاسم', $prompt);
        $this->assertStringNotContainsString('أول 3 شهور', $prompt);
        $this->assertStringNotContainsString('80%', $prompt);
        $this->assertStringNotContainsString('تركيب مجاني', $prompt);

        $opening = app(\App\Services\Malan\Campaigns\MalanCampaignService::class)
            ->defaultOpeningMessage($instance);
        $this->assertStringContainsString('السلام عليكم', $opening);
        $this->assertStringContainsString('ممدوح', $opening);
        $this->assertStringContainsString('سبيدكوم', $opening);
        $this->assertStringContainsString('299', $opening);
        $this->assertStringContainsString('5000', $opening);
        $this->assertStringNotContainsString('ملان', $opening);
        $this->assertStringNotContainsString('سالي', $opening);
        $this->assertStringNotContainsString('أول 3 شهور', $opening);

        $burst = app(\App\Services\Malan\Campaigns\CampaignConversationBurstService::class)
            ->buildBurstEphemeralPrompt([
                new \App\Models\AiChatbot\ChatbotMessage(['message' => 'قديش السعر؟']),
            ], $instance);
        $this->assertStringContainsString('299', $burst);
        $this->assertStringContainsString('5000', $burst);
        $this->assertStringNotContainsString('80%', $burst);
        $this->assertStringNotContainsString('Kafr Qasim', $burst);
        $this->assertStringNotContainsString('29 for 3', $burst);
        $this->assertStringNotContainsString('سالي', $burst);

        $profile = $instance->companyProfile();
        $installReply = $profile->localize(\App\Jobs\ProcessCampaignConversationJob::NO_FIBER_INSTALL_REPLY);
        $priceReply = $profile->localize(\App\Jobs\ProcessCampaignConversationJob::NO_FIBER_PRICE_REPLY);
        $this->assertStringContainsString('299', $installReply);
        $this->assertStringContainsString('99 شيكل', $priceReply);
        $this->assertStringNotContainsString('مجاني', $installReply);
        $this->assertStringNotContainsString('أول 3 شهور', $priceReply);

        $tools = app(ChatbotToolDefinitions::class)->forInstance(
            $instance,
            'whatsapp',
            false,
            new \App\Models\AiChatbot\ChatbotConversation([
                'campaign_id' => 1,
                'metadata' => ['campaign_lead_bot' => true],
            ]),
        );
        $this->assertNotSame([], $tools);
        $encoded = json_encode($tools, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('رهط', $encoded);
        $this->assertStringNotContainsString('الطيبة', $encoded);
    }
}
