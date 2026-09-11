<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Data\Malan\BankTransferProofVerificationResult;
use App\Data\Malan\MalanCustomerLookupResult;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Models\Malan\MalanPaymentProof;
use App\Models\Malan\MalanSupportReport;
use App\Models\User;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\Malan\Contracts\BankTransferProofVerifier;
use App\Services\Malan\MalanConversationContextService;
use App\Services\Malan\Proof\MalanPaymentProofService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MalanChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);

        config([
            'services.openai.api_key' => 'test-openai',
            'malan.api.base_url' => 'https://www.malan.app',
            'malan.api.key' => 'test-malan-key',
            'malan.api.retries' => 0,
        ]);
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

    public function test_tools_not_available_for_other_bots(): void
    {
        $instance = ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => null,
        ]);

        $tools = app(ChatbotToolDefinitions::class)->forInstance($instance);

        $this->assertSame([], $tools);
    }

    public function test_tenant_isolation_blocks_cross_instance_conversation(): void
    {
        $user = $this->user();
        $a = $this->malanInstance($user);
        $b = $this->malanInstance($user);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $a->id,
            'title' => 't',
        ]);

        $result = app(ChatbotToolExecutor::class)->execute(
            $b,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'phone', 'value' => '0536079841', 'reason' => 'internet_outage'],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Conversation/instance mismatch.', $result['message']);
    }

    public function test_cannot_create_support_report_before_verified_lookup(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'customer_id' => 'FAKE-999',
                'confirmed_by_customer' => true,
            ],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, MalanSupportReport::query()->count());
    }

    public function test_ai_cannot_pass_fake_customer_id_to_support_report(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => '*****3153',
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'customer_id' => 'FAKE-999',
                'confirmed_by_customer' => true,
            ],
            'web',
        );

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('malan_support_reports', [
            'external_customer_id' => '3119',
        ]);
        $this->assertDatabaseMissing('malan_support_reports', [
            'external_customer_id' => 'FAKE-999',
        ]);
    }

    public function test_support_report_requires_customer_confirmation(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'create_malan_support_report',
            [
                'issue_type' => 'full_outage',
                'summary' => 'outage',
                'confirmed_by_customer' => false,
            ],
            'web',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('confirmation_required', $result['error_code'] ?? null);
        $this->assertSame(0, MalanSupportReport::query()->count());
    }

    public function test_create_malan_task_requires_confirmation_then_posts_to_accounting(): void
    {
        config(['malan.tasks.accounting_user_id' => 147]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '630',
                    'name' => 'Debt Client',
                    'phone_masked' => '052***5553',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -200.0, 'debt_amount' => 200.0, 'currency' => 'ILS'],
            ),
        );

        $executor = app(ChatbotToolExecutor::class);

        $blocked = $executor->execute($instance, $conversation, 'create_malan_task', [
            'department' => 'accounting',
            'subject' => 'متابعة ניתוק חוב',
            'confirmed_by_customer' => false,
            'to_user_id' => 999,
            'client_id' => 1,
        ], 'whatsapp');

        $this->assertFalse($blocked['success']);
        $this->assertSame('confirmation_required', $blocked['error_code'] ?? null);

        Http::fake([
            'www.malan.app/apiClient/createTask' => Http::response([
                'result' => true,
                'data' => ['task_id' => 555],
            ], 200),
        ]);

        $created = $executor->execute($instance, $conversation, 'create_malan_task', [
            'department' => 'accounting',
            'title' => 'متابعة محاسبة',
            'subject' => 'زبون ניתוק חוב — متابعة محاسبة',
            'status' => 'urgent',
            'confirmed_by_customer' => true,
            'to_user_id' => 999,
            'client_id' => 1,
        ], 'whatsapp');

        $this->assertTrue($created['success']);
        $this->assertSame(555, $created['task_id']);
        $this->assertSame(147, $created['to_user_id']);
        $this->assertSame('accounting', $created['department']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['to_user_id'] === 147
                && $request['client_id'] === 630
                && $request['status'] === 'urgent';
        });

        $this->assertDatabaseHas('malan_support_reports', [
            'external_customer_id' => '630',
            'issue_type' => 'accounting',
        ]);
    }

    public function test_create_malan_lead_requires_confirmation_then_posts(): void
    {
        config(['malan.leads.default_source_id' => 7]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $executor = app(ChatbotToolExecutor::class);

        $blocked = $executor->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => 'أحمد علي',
            'phone' => '0501234567',
            'city_name' => 'كفرقاسم',
            'confirmed_by_customer' => false,
            'leads_sources_id' => 99,
        ], 'whatsapp');

        $this->assertFalse($blocked['success']);
        $this->assertSame('confirmation_required', $blocked['error_code'] ?? null);

        Http::fake([
            'www.malan.app/apiClient/createLead' => Http::response([
                'result' => true,
                'data' => ['lead_id' => 88, 'leads_sources_id' => 7, 'statuses_id' => 4],
            ], 201),
        ]);

        $created = $executor->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => 'أحمد علي',
            'phone' => '0501234567',
            'city_name' => 'كفرقاسم',
            'confirmed_by_customer' => true,
            'leads_sources_id' => 99,
        ], 'whatsapp');

        $this->assertTrue($created['success']);
        $this->assertSame(88, $created['lead_id']);
        $this->assertSame(7, $created['leads_sources_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['leads_sources_id'] === 7
                && $request['full_name'] === 'أحمد علي'
                && $request['phone'] === '0501234567'
                && $request['city_name'] === 'كفرقاسم';
        });

        $this->assertDatabaseHas('malan_support_reports', [
            'issue_type' => 'new_lead',
            'external_customer_id' => 'lead:0501234567',
        ]);
    }

    public function test_campaign_create_malan_lead_uses_source_id_66(): void
    {
        config([
            'malan.leads.default_source_id' => 7,
            'malan.leads.campaign_source_id' => 66,
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = \App\Models\Malan\MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Lead Source',
            'status' => \App\Models\Malan\MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => \App\Models\Malan\MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        Http::fake([
            'www.malan.app/apiClient/createLead' => Http::response([
                'result' => true,
                'data' => ['lead_id' => 501, 'leads_sources_id' => 66, 'statuses_id' => 4],
            ], 201),
        ]);

        $created = app(ChatbotToolExecutor::class)->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => 'صبحي أحمد',
            'phone' => '0584680001',
            'city_name' => 'الطيبة',
            'confirmed_by_customer' => true,
            'leads_sources_id' => 99,
        ], 'whatsapp');

        $this->assertTrue($created['success']);
        $this->assertSame(66, $created['leads_sources_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['leads_sources_id'] === 66
                && $request['full_name'] === 'صبحي أحمد';
        });
    }

    public function test_create_malan_lead_rejects_phone_digits_as_full_name(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = \App\Models\Malan\MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Name is phone',
            'status' => \App\Models\Malan\MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => \App\Models\Malan\MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        Http::fake();

        $result = app(ChatbotToolExecutor::class)->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => '0533046830',
            'phone' => '0533046830',
            'city_name' => 'الطيبة',
            'confirmed_by_customer' => true,
        ], 'whatsapp');

        $this->assertFalse($result['success']);
        $this->assertSame('name_is_phone', $result['error_code'] ?? null);

        $context = app(\App\Services\Malan\MalanConversationContextService::class)->getActive($conversation);
        $this->assertSame('0533046830', app(\App\Services\Malan\MalanConversationContextService::class)->campaignLeadPhone($context));

        Http::assertNothingSent();
    }

    public function test_campaign_create_malan_lead_sets_fiber_and_posts_call_time_note(): void
    {
        config([
            'malan.api.key' => 'test-malan-key',
            'malan.leads.campaign_source_id' => 66,
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = \App\Models\Malan\MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Fiber note',
            'status' => \App\Models\Malan\MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => \App\Models\Malan\MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972533046830@c.us',
            'contact_phone' => '0533046830',
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'sender_type' => 'ai',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_AI,
            'message_type' => 'text',
            'message' => \App\Jobs\ProcessCampaignConversationJob::FIBER_AT_HOME_QUESTION,
        ]);

        $memory = app(\App\Services\Malan\MalanConversationMemoryService::class);
        $memory->observeUserMessage($conversation, $instance, 'اه عندنا');
        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => 'اه عندنا',
        ]);
        \App\Models\AiChatbot\ChatbotMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'sender_type' => 'customer',
            'reply_source' => \App\Models\AiChatbot\ChatbotMessage::REPLY_SOURCE_CUSTOMER,
            'message_type' => 'text',
            'message' => 'بس اتصلوا الساعة 16:00',
        ]);

        Http::fake([
            'www.malan.app/apiClient/createLead' => Http::response([
                'result' => true,
                'data' => ['lead_id' => 4720, 'leads_sources_id' => 66, 'statuses_id' => 4],
            ], 201),
            'www.malan.app/apiClient/createLeadNote' => Http::response([
                'result' => true,
            ], 201),
        ]);

        $created = app(ChatbotToolExecutor::class)->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => 'صبحي',
            'phone' => 'whatsapp_chat_phone',
            'city_name' => 'الطيبة',
            'confirmed_by_customer' => true,
        ], 'whatsapp');

        $this->assertTrue($created['success']);
        $this->assertSame(4720, $created['lead_id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/apiClient/createLead')
                && $request['with_fiber'] === 1
                && $request['full_name'] === 'صبحي';
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/apiClient/createLeadNote')
                && (int) $request['lead_id'] === 4720
                && str_contains((string) $request['note'], '16:00')
                && str_contains((string) $request['note'], 'סיב אופטי');
        });
    }

    public function test_campaign_create_malan_lead_resolves_whatsapp_chat_phone_and_forces_taybee_city(): void
    {
        config([
            'malan.leads.default_source_id' => 7,
            'malan.leads.campaign_source_id' => 66,
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $campaign = \App\Models\Malan\MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $user->id,
            'name' => 'Lead WA phone',
            'status' => \App\Models\Malan\MalanCampaign::STATUS_READY,
            'is_active' => true,
            'greenapi_url' => 'https://example.com/waInstance1/sendMessage/token',
            'opening_message' => 'hello',
            'system_prompt' => \App\Models\Malan\MalanCampaign::defaultLeadSystemPrompt(),
            'contacts_count' => 0,
        ]);

        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
            'campaign_id' => $campaign->id,
            'channel' => ChatbotConversation::CHANNEL_WHATSAPP,
            'external_chat_id' => '972533046830@c.us',
            'contact_phone' => '0533046830',
            'metadata' => ['campaign_lead_bot' => true],
        ]);

        Http::fake([
            'www.malan.app/apiClient/createLead' => Http::response([
                'result' => true,
                'data' => ['lead_id' => 777, 'leads_sources_id' => 66, 'statuses_id' => 4],
            ], 201),
        ]);

        $created = app(ChatbotToolExecutor::class)->execute($instance, $conversation, 'create_malan_lead', [
            'full_name' => 'محمد عيسى',
            'phone' => 'whatsapp_chat_phone',
            'city_name' => 'كفر قاسم',
            'confirmed_by_customer' => true,
        ], 'whatsapp');

        $this->assertTrue($created['success'] ?? false, json_encode($created, JSON_UNESCAPED_UNICODE));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/apiClient/createLead')) {
                return false;
            }
            $phone = preg_replace('/\D+/', '', (string) ($request['phone'] ?? '')) ?? '';
            $city = (string) ($request['city_name'] ?? '');

            return str_contains($phone, '533046830')
                && $city === 'الطيبة'
                && (string) ($request['full_name'] ?? '') === 'محمد عيسى'
                && (int) ($request['leads_sources_id'] ?? 0) === 66;
        });
    }

    public function test_lookup_is_blocked_during_new_signup_intake(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->beginNewSignup($conversation, $instance);

        Http::fake();

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'identity', 'value' => '331726943', 'reason' => 'account_status'],
            'whatsapp',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('new_signup_in_progress', $result['error_code'] ?? null);
        $this->assertStringNotContainsString('create_malan_lead', (string) ($result['message'] ?? ''));
        Http::assertNothingSent();
    }

    public function test_lookup_stays_blocked_after_prior_verified_customer_when_new_signup_starts(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $contextService = app(MalanConversationContextService::class);
        $contextService->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '1513',
                    'name' => 'Old',
                    'phone_masked' => '054***8848',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $cleared = $contextService->beginNewSignup($conversation, $instance);
        $this->assertFalse($cleared->hasVerifiedCustomer());
        $this->assertSame('new_signup_intake', $cleared->pending_flow);

        \App\Models\AiChatbot\ChatbotMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'message' => 'بدي اركب انترنت بدار سيدي',
            'message_type' => 'text',
            'reply_source' => 'customer',
        ]);

        Http::fake();

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'phone', 'value' => '0584680001', 'reason' => 'account_status', 'force_refresh' => true],
            'whatsapp',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('new_signup_in_progress', $result['error_code'] ?? null);
        $this->assertStringNotContainsString('create_malan_lead', (string) ($result['message'] ?? ''));
        Http::assertNothingSent();
    }

    public function test_lookup_recovers_from_sticky_signup_when_recent_outage_message_exists(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->beginNewSignup($conversation, $instance);

        \App\Models\AiChatbot\ChatbotMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'message' => 'عندي مشكله بلانترنت',
            'message_type' => 'text',
            'reply_source' => 'customer',
        ]);

        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '3481',
                        'client_name' => 'Test',
                        'client_phone' => '0533046830',
                        'client_identity' => '*****6943',
                        'status' => 'ACTIVE',
                    ],
                    'financial_summary' => ['balance' => 0],
                    'internet_services' => [[
                        'package_name' => 'X',
                        'radius_status' => [
                            'request_succeeded' => true,
                            'state' => 'offline',
                            'is_online' => false,
                        ],
                    ]],
                ],
            ]], 200),
        ]);

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'identity', 'value' => '331726943', 'reason' => 'internet_outage', 'force_refresh' => true],
            'whatsapp',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ACTIVE', $result['customer']['status'] ?? null);
        $this->assertSame('other', $result['radius']['classification'] ?? null);
        $this->assertStringNotContainsString('create_malan_lead', (string) ($result['message'] ?? ''));
    }

    public function test_duplicate_support_report_is_prevented(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'ACTIVE',
                    'city' => null,
                ],
                financial: ['balance_raw' => 0.0, 'debt_amount' => null, 'currency' => 'ILS'],
            ),
        );

        $executor = app(ChatbotToolExecutor::class);
        $first = $executor->execute($instance, $conversation, 'create_malan_support_report', [
            'issue_type' => 'full_outage',
            'summary' => 'one',
            'confirmed_by_customer' => true,
        ], 'web');
        $second = $executor->execute($instance, $conversation, 'create_malan_support_report', [
            'issue_type' => 'full_outage',
            'summary' => 'two',
            'confirmed_by_customer' => true,
        ], 'web');

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['duplicate'] ?? false);
        $this->assertSame(1, MalanSupportReport::query()->count());
    }

    public function test_visa_charge_returns_integration_pending(): void
    {
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $result = app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'charge_malan_saved_payment_method',
            ['confirmed_by_customer' => true],
            'whatsapp',
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['integration_pending']);
    }

    public function test_tool_arguments_are_masked_in_executions_table(): void
    {
        Http::fake([
            'www.malan.app/apiClient/getClient*' => Http::response([[
                'result' => true,
                'data' => [
                    'client' => [
                        'id' => '3119',
                        'client_name' => 'Test',
                        'client_phone' => '0536079841',
                        'client_identity' => '123456782',
                        'status' => 'ACTIVE',
                    ],
                    'financial_summary' => ['balance' => 0],
                ],
            ]], 200),
        ]);

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(ChatbotToolExecutor::class)->execute(
            $instance,
            $conversation,
            'lookup_malan_customer',
            ['lookup_type' => 'identity', 'value' => '123456782', 'reason' => 'internet_outage'],
            'web',
        );

        $execution = ChatbotToolExecution::query()->firstOrFail();
        $this->assertStringNotContainsString('123456782', json_encode($execution->arguments) ?: '');
        $this->assertStringContainsString('*', (string) ($execution->arguments['value'] ?? ''));
    }

    public function test_image_without_pending_bank_transfer_is_not_treated_as_proof(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $path = 'malan/payment-proofs/sample.jpg';
        Storage::disk('local')->put($path, 'fake-image');

        $result = app(MalanPaymentProofService::class)->handleIncomingProofFile(
            $instance,
            $conversation,
            $path,
            'image/jpeg',
            'msg-1',
        );

        $this->assertFalse($result['handled']);
        $this->assertFalse($result['awaiting_proof']);
        $this->assertSame(0, MalanPaymentProof::query()->count());
    }

    public function test_proof_with_wrong_amount_is_rejected_or_needs_review(): void
    {
        Storage::fake('local');
        $this->app->instance(BankTransferProofVerifier::class, new class implements BankTransferProofVerifier
        {
            public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult
            {
                return new BankTransferProofVerificationResult(
                    status: BankTransferProofVerificationResult::STATUS_REJECTED,
                    detectedAmount: 100.0,
                    detectedDate: $expectations['expected_date'],
                    referenceNumber: 'ABC',
                    amountMatch: false,
                    dateMatch: true,
                    referencePresent: true,
                    suspicionReasons: ['amount_mismatch_or_missing'],
                    confidence: 0.9,
                );
            }
        });

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        $context = app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );
        app(MalanConversationContextService::class)->setPaymentMethod(
            $conversation,
            $instance,
            'bank_transfer',
            'awaiting_bank_transfer_proof',
        );

        $path = 'malan/payment-proofs/wrong.jpg';
        Storage::disk('local')->put($path, 'fake');

        $result = app(MalanPaymentProofService::class)->handleIncomingProofFile(
            $instance,
            $conversation,
            $path,
            'image/jpeg',
            'msg-amount',
        );

        $this->assertTrue($result['handled']);
        $this->assertSame('rejected', $result['verification']->status);
        $this->assertDatabaseHas('malan_payment_proofs', [
            'verification_status' => 'rejected',
            'greenapi_message_id' => 'msg-amount',
        ]);
        unset($context);
    }

    public function test_duplicate_webhook_message_does_not_create_second_proof(): void
    {
        Storage::fake('local');
        $this->app->instance(BankTransferProofVerifier::class, new class implements BankTransferProofVerifier
        {
            public function verify(string $absoluteFilePath, array $expectations): BankTransferProofVerificationResult
            {
                return new BankTransferProofVerificationResult(
                    status: BankTransferProofVerificationResult::STATUS_NEEDS_REVIEW,
                    detectedAmount: 318.0,
                    detectedDate: $expectations['expected_date'],
                    referenceNumber: null,
                    amountMatch: true,
                    dateMatch: true,
                    referencePresent: false,
                    suspicionReasons: ['missing_reference_number'],
                    confidence: 0.5,
                );
            }
        });

        $user = $this->user();
        $instance = $this->malanInstance($user);
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        app(MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Real',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );
        app(MalanConversationContextService::class)->setPaymentMethod(
            $conversation,
            $instance,
            'bank_transfer',
            'awaiting_bank_transfer_proof',
        );

        $path = 'malan/payment-proofs/dup.jpg';
        Storage::disk('local')->put($path, 'fake');
        $service = app(MalanPaymentProofService::class);

        $service->handleIncomingProofFile($instance, $conversation, $path, 'image/jpeg', 'msg-dup');
        $service->handleIncomingProofFile($instance, $conversation, $path, 'image/jpeg', 'msg-dup');

        $this->assertSame(1, MalanPaymentProof::query()->count());
    }
}
