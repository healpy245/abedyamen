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
        $this->assertStringContainsString('سبيدكوم', (string) $instance->system_prompt);
        $this->assertStringContainsString('رهط', (string) $instance->system_prompt);
        $this->assertStringContainsString('اللقية', (string) $instance->system_prompt);
        $this->assertStringNotContainsString('كفرقاسم', (string) $instance->system_prompt);
        $this->assertStringNotContainsString('ملان انترنت', (string) $instance->system_prompt);
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
        $this->assertStringContainsString('سبيدكوم', $prompt);
        $this->assertStringContainsString('رهط', $prompt);
        $this->assertStringNotContainsString('الطيبة', $prompt);
        $this->assertStringNotContainsString('ملان', $prompt);

        $opening = app(\App\Services\Malan\Campaigns\MalanCampaignService::class)
            ->defaultOpeningMessage($instance);
        $this->assertStringContainsString('سبيدكوم', $opening);
        $this->assertStringNotContainsString('ملان', $opening);

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
