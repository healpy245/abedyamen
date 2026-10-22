<?php

declare(strict_types=1);

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\AiChatbot\SpeedcomDeskNotifier;
use App\Services\AiChatbot\Tools\ChatbotToolDefinitions;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpeedcomDeskNotifierTest extends TestCase
{
    use RefreshDatabase;

    private ChatbotInstance $instance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SpeedcomChatbotInstanceSeeder::class);

        $this->owner = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $this->instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->firstOrFail();
        $this->instance->forceFill([
            'greenapi_url' => 'https://api.green-api.com/waInstance1/sendMessage/token',
        ])->save();

        Http::preventStrayRequests();
        Http::fake([
            'api.green-api.com/*' => Http::response(['idMessage' => 'desk-1'], 200),
        ]);
    }

    public function test_new_customer_sends_fixed_lead_template_instead_of_malan(): void
    {
        $conversation = $this->conversation();

        $result = app(ChatbotToolExecutor::class)->execute($this->instance, $conversation, 'create_malan_lead', [
            'full_name' => 'أحمد علي',
            'phone' => '0501234567',
            'city_name' => 'رهط',
            'with_fiber' => 0,
            'note' => 'يفضل العصر',
            'confirmed_by_customer' => true,
        ], 'whatsapp');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['duplicate']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['chatId'] ?? '') === '972584680001@c.us'
                && ($data['message'] ?? '') === implode("\n", [
                    'اشتراك جديد — سبيدكوم',
                    'الاسم: أحمد علي',
                    'التلفون: 0501234567',
                    'البلدة: رهط',
                    'فايبر بالبيت: لا',
                    'ملاحظة: يفضل العصر',
                ]);
        });

        $this->assertDatabaseHas('malan_support_reports', [
            'chatbot_instance_id' => $this->instance->id,
            'issue_type' => 'new_lead',
            'customer_name' => 'أحمد علي',
        ]);
    }

    public function test_technical_problem_sends_fixed_task_template_without_a_crm_customer(): void
    {
        $conversation = $this->conversation();
        $conversation->forceFill([
            'contact_phone' => '972501112233',
            'contact_name' => 'سامي',
        ])->save();

        $result = app(ChatbotToolExecutor::class)->execute($this->instance, $conversation, 'create_malan_task', [
            'department' => 'technical',
            'subject' => 'الراوتر ضاوي والضوء أحمر ضعيف والنت مقطوع بالمرة',
            'customer_name' => 'سامي',
            'phone' => 'whatsapp_chat_phone',
            'router_power' => 'on',
            'router_light' => 'red_weak',
            'internet' => 'none',
            'confirmed_by_customer' => true,
        ], 'whatsapp');

        $this->assertTrue($result['success']);
        $this->assertSame('technical', $result['department']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['chatId'] ?? '') === '972584680001@c.us'
                && ($data['message'] ?? '') === implode("\n", [
                    'مشكلة تقنية — سبيدكوم',
                    'الاسم: سامي',
                    'التلفون: 0501112233',
                    'الراوتر: ضاوي',
                    'الضوء: أحمر ضعيف',
                    'النت: مقطوع بالمرة',
                    'التفاصيل: الراوتر ضاوي والضوء أحمر ضعيف والنت مقطوع بالمرة',
                ]);
        });
    }

    public function test_duplicate_handoff_does_not_send_twice(): void
    {
        $conversation = $this->conversation();
        $payload = [
            'full_name' => 'أحمد علي',
            'phone' => '0501234567',
            'city_name' => 'رهط',
            'confirmed_by_customer' => true,
        ];

        $executor = app(ChatbotToolExecutor::class);
        $this->assertTrue($executor->execute($this->instance, $conversation, 'create_malan_lead', $payload, 'whatsapp')['success']);
        $second = $executor->execute($this->instance, $conversation, 'create_malan_lead', $payload, 'whatsapp');

        $this->assertTrue($second['success']);
        $this->assertTrue($second['duplicate']);
        Http::assertSentCount(1);
    }

    public function test_desk_number_comes_from_config(): void
    {
        config(['malan.speedcom.desk_phone' => '0520000000']);

        $this->assertSame('972520000000@c.us', app(SpeedcomDeskNotifier::class)->deskChatId());
    }

    public function test_speedcom_tools_describe_whatsapp_templates_not_crm_storage(): void
    {
        $tools = app(ChatbotToolDefinitions::class)->forInstance($this->instance);
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool['function']['name']] = $tool['function'];
        }

        $this->assertStringContainsString('fixed WhatsApp template', $byName['create_malan_lead']['description']);
        $this->assertStringContainsString('do NOT create a Malan CRM lead', $byName['create_malan_lead']['description']);
        $this->assertArrayHasKey('router_power', $byName['create_malan_task']['parameters']['properties']);
        $this->assertArrayHasKey('internet', $byName['create_malan_task']['parameters']['properties']);
        $this->assertStringContainsString('Does not create a Malan CRM task', $byName['create_malan_task']['description']);
    }

    private function conversation(): ChatbotConversation
    {
        return ChatbotConversation::query()->create([
            'user_id' => $this->owner->id,
            'instance_id' => $this->instance->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972501112233@c.us',
        ]);
    }
}
