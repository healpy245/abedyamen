<?php

namespace Tests\Feature\AiChatbot;

use App\Enums\Project;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Malan\MalanCampaign;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Database\Seeders\KamanWhatsappChatbotInstanceSeeder;
use Database\Seeders\SallyMalanChatbotInstanceSeeder;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Database\Seeders\SpeedcomCompanyMemberSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpeedcomCompanyMemberAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SallyMalanChatbotInstanceSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);
        $this->seed(SpeedcomChatbotInstanceSeeder::class);
        $this->seed(SpeedcomCompanyMemberSeeder::class);
    }

    public function test_speedcom_member_has_only_the_chatbot_project(): void
    {
        $member = User::where('email', SpeedcomCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertFalse((bool) $member->is_admin);
        $this->assertSame(['ai-chatbot'], $member->projectKeys());
        $this->assertTrue($member->canAccessProject('ai-chatbot'));
        $this->assertFalse($member->canAccessProject('form'));
        $this->assertFalse($member->canAccessProject('kaman-support'));
        $this->assertFalse($member->canAccessProject('app-development'));
    }

    public function test_ibrahim_can_only_open_mamdouh(): void
    {
        $member = User::where('email', SpeedcomCompanyMemberSeeder::IBRAHIM_EMAIL)->firstOrFail();
        $authz = app(ChatbotAuthorizationService::class);
        $instances = $authz->instancesForUser($member);

        $this->assertCount(1, $instances);
        $this->assertTrue($instances->first()->companyProfile()->isSpeedcom());
        $this->assertSame(SpeedcomChatbotInstanceSeeder::INSTANCE_NAME, $instances->first()->name);
        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $member->id)->count());

        $this->actingAs($member)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $instances->first()));
    }

    public function test_speedcom_member_can_only_open_mamdouh(): void
    {
        $member = User::where('email', SpeedcomCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $authz = app(ChatbotAuthorizationService::class);
        $instances = $authz->instancesForUser($member);

        $this->assertCount(1, $instances);
        $this->assertTrue($instances->first()->companyProfile()->isSpeedcom());
        $this->assertSame(SpeedcomChatbotInstanceSeeder::INSTANCE_NAME, $instances->first()->name);

        $kaman = ChatbotInstance::query()->where('integration_type', 'kaman_whatsapp')->firstOrFail();
        $malan = ChatbotInstance::query()->where('integration_type', 'malan')->firstOrFail();

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $kaman))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $malan))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $instances->first()));

        foreach ([Project::Form, Project::KamanSupport, Project::AppDevelopment] as $project) {
            $this->actingAs($member)->get($project->url())->assertForbidden();
        }
    }

    public function test_speedcom_member_lands_in_mamdouh_workspace_without_a_bot_switcher(): void
    {
        $member = User::where('email', SpeedcomCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $instance = app(ChatbotAuthorizationService::class)->firstAccessibleForUser($member);

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $instance))
            ->assertOk()
            ->assertSee(__('projects.ai-chatbot.label'))
            ->assertDontSee(__('projects.form.label'))
            ->assertDontSee(__('projects.kaman-support.label'));

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $instance))
            ->assertOk()
            ->assertSee($instance->name)
            ->assertSee(__('chatbot.workspace.nav_settings'), false)
            ->assertDontSee(__('chatbot.choose.switch'))
            ->assertDontSee('Kaman POS');
    }

    public function test_bot_only_account_hides_instructor_downloads_and_simplifies_settings(): void
    {
        Http::fake([
            '*/getWaSettings/*' => Http::response(['phone' => '972584680001']),
            '*/getContactInfo/*' => Http::response(['name' => 'سبيدكوم نت']),
        ]);

        $member = User::where('email', SpeedcomCompanyMemberSeeder::IBRAHIM_EMAIL)->firstOrFail();
        $instance = ChatbotInstance::query()
            ->where('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME)
            ->firstOrFail();
        $prompt = (string) $instance->system_prompt;
        $instance->forceFill([
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance7107/sendMessage/token-abc',
        ])->save();

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $instance->user_id,
            'instance_id' => $instance->id,
            'channel' => 'whatsapp',
            'external_chat_id' => '972501112233@c.us',
            'title' => 'زبون',
        ]);

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations.show', [$instance, $conversation]))
            ->assertOk()
            ->assertDontSee(__('chatbot.workspace.instructor'))
            ->assertDontSee(__('app.download_pos_android'))
            ->assertDontSee(__('app.download_pos_windows'))
            ->assertDontSee(__('app.download_support_android'));

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.settings', $instance))
            ->assertOk()
            ->assertSee('سبيدكوم نت')
            ->assertSee('0584680001')
            ->assertSee('7107')
            ->assertSee('lg:grid-cols-3', false)
            ->assertSee(__('chatbot.whapi_whapi'))
            ->assertSee('data-prompt-fit', false)
            ->assertSee('min-height:72vh', false)
            ->assertSee('بدك اشتراك جديد')
            ->assertDontSee(__('chatbot.workspace.section_restrictions'))
            ->assertDontSee('name="prompt_sections[identity][bot_name]"', false)
            ->assertDontSee(__('chatbot.greenapi_desc'))
            ->assertDontSee(__('chatbot.whapi_help'))
            ->assertDontSee(__('chatbot.workspace.compiled_preview'))
            ->assertDontSee(__('chatbot.workspace.clear_conversations_title'));

        $this->actingAs($member)
            ->put(route('ai-chatbot.workspace.settings.update', $instance), [
                'name' => $instance->name,
                'greenapi_url' => $instance->greenapi_url,
            ])
            ->assertRedirect(route('ai-chatbot.workspace.settings', $instance));

        $instance->refresh();
        $this->assertSame($prompt, $instance->system_prompt);

        $this->actingAs($member)
            ->put(route('ai-chatbot.workspace.settings.update', $instance), [
                'name' => $instance->name,
                'greenapi_url' => $instance->greenapi_url,
                'system_prompt' => "وصف واحد للبوت.\nبدك اشتراك جديد؟",
            ])
            ->assertRedirect(route('ai-chatbot.workspace.settings', $instance));

        $instance->refresh();
        $this->assertSame("وصف واحد للبوت.\nبدك اشتراك جديد؟", $instance->system_prompt);
        $instance->forceFill(['system_prompt' => $prompt])->save();

        $campaign = MalanCampaign::query()->create([
            'chatbot_instance_id' => $instance->id,
            'created_by_user_id' => $instance->user_id,
            'name' => 'حملة سبيدكوم',
            'status' => MalanCampaign::STATUS_READY,
            'greenapi_url' => 'https://7107.api.greenapi.com/waInstance7107/sendMessage/token-abc',
            'opening_message' => 'مرحبا',
            'system_prompt' => 'بيع الفايبر',
        ]);

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.campaigns', $instance))
            ->assertOk()
            ->assertSee('lg:grid-cols-4', false)
            ->assertSee('سبيدكوم نت')
            ->assertDontSee(__('chatbot.workspace.campaigns.leads_only_note'))
            ->assertDontSee(__('chatbot.workspace.campaigns.separate_greenapi_note'));

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.campaigns.settings', [$instance, $campaign]))
            ->assertOk()
            ->assertSee('سبيدكوم نت')
            ->assertSee('بيع الفايبر')
            ->assertSee(__('chatbot.whapi_whapi'))
            ->assertSee('data-prompt-fit', false)
            ->assertSee(__('chatbot.workspace.campaigns.system_prompt'))
            ->assertDontSee(__('chatbot.workspace.campaigns.system_prompt_help'))
            ->assertDontSee(__('chatbot.workspace.campaigns.bot_independent_help'))
            ->assertDontSee(__('chatbot.workspace.campaigns.leads_only_note'));
    }

    public function test_speedcom_member_owns_no_chatbot_instances(): void
    {
        $member = User::where('email', SpeedcomCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $member->id)->count());
    }
}
