<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanCampaign;
use App\Models\Malan\MalanCampaignContact;
use App\Models\User;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\Malan\Campaigns\MalanCampaignService;
use App\Services\Malan\Campaigns\MalanCampaignWebhookService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MalanCampaignLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_outgoing_webhook_is_ignored_by_campaign_handler(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance111/sendMessage/token-main',
        ]);

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Loop',
            'status' => MalanCampaign::STATUS_READY,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance111/sendMessage/token-main',
            'opening_message' => 'hello',
            'contacts_count' => 1,
        ]);

        $request = Request::create('/webhook', 'POST', [
            'typeWebhook' => 'outgoingAPIMessageReceived',
            'idMessage' => 'OUT1',
            'senderData' => ['chatId' => '972533046830@c.us'],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => ['textMessage' => 'hello'],
                'fromMe' => true,
            ],
        ]);

        $result = app(MalanCampaignWebhookService::class)->handle($campaign, $request);

        $this->assertTrue($result['ignored'] ?? false);
        $this->assertStringContainsString('non-inbound', (string) ($result['reason'] ?? ''));
    }

    public function test_shared_greenapi_campaign_can_start(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $url = 'https://7107.api.greenapi.com/waInstance7107621968/sendMessage/token-main';
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
            'greenapi_url' => $url,
        ]);

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Shared',
            'status' => MalanCampaign::STATUS_READY,
            'greenapi_url' => $url,
            'opening_message' => 'hello',
            'contacts_count' => 1,
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Test',
            'phone' => '0533046830',
            'phone_normalized' => '0533046830',
            'chat_id' => '972533046830@c.us',
            'status' => MalanCampaignContact::STATUS_PENDING,
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'MSG1'], 200),
        ]);

        $started = app(MalanCampaignService::class)->start($campaign->fresh());
        $this->assertTrue(in_array($started['campaign']->status, [
            MalanCampaign::STATUS_RUNNING,
            MalanCampaign::STATUS_COMPLETED,
        ], true));
        $this->assertGreaterThanOrEqual(1, $started['sent']);
    }

    public function test_main_webhook_routes_campaign_contact_to_leads_bot(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $url = 'https://7107.api.greenapi.com/waInstance7107621968/sendMessage/token-main';
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'integration_type' => 'malan',
            'is_active' => true,
            'greenapi_url' => $url,
            'greenapi_webhook_token' => 'tok123',
        ]);

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Shared',
            'status' => MalanCampaign::STATUS_COMPLETED,
            'greenapi_url' => $url,
            'opening_message' => 'hello campaign',
            'contacts_count' => 1,
        ]);

        MalanCampaignContact::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Test',
            'phone' => '0533046830',
            'phone_normalized' => '0533046830',
            'chat_id' => '972533046830@c.us',
            'status' => MalanCampaignContact::STATUS_SENT,
            'message_sent_at' => now(),
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'تمام، قوليلي اسمك والبلدة',
                    ],
                ]],
            ], 200),
            '*' => Http::response(['idMessage' => 'REPLY1'], 200),
        ]);

        config(['services.openai.api_key' => 'test-key']);

        $request = Request::create('/webhook', 'POST', [
            'typeWebhook' => 'incomingMessageReceived',
            'idMessage' => 'IN1',
            'senderData' => [
                'chatId' => '972533046830@c.us',
                'senderName' => 'Test',
            ],
            'messageData' => [
                'typeMessage' => 'textMessage',
                'textMessageData' => ['textMessage' => 'مهتم'],
                'fromMe' => false,
            ],
        ]);

        $result = app(ChatbotGreenApiService::class)->handleWebhook($instance, $request);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('campaign_lead_bot', $result['routed'] ?? null);
        $this->assertSame($campaign->id, $result['campaign_id'] ?? null);
    }
}
