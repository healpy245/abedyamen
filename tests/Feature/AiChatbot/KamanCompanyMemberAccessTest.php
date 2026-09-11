<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Database\Seeders\KamanCompanyMemberSeeder;
use Database\Seeders\KamanWhatsappChatbotInstanceSeeder;
use Database\Seeders\MalanCompanyMemberSeeder;
use Database\Seeders\SallyMalanChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamanCompanyMemberAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SallyMalanChatbotInstanceSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);
        $this->seed(MalanCompanyMemberSeeder::class);
        $this->seed(KamanCompanyMemberSeeder::class);
    }

    public function test_kaman_member_has_only_chatbot_project(): void
    {
        $member = User::where('email', KamanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertFalse((bool) $member->is_admin);
        $this->assertSame(['ai-chatbot'], $member->projectKeys());
        $this->assertTrue($member->canAccessProject('ai-chatbot'));
        $this->assertFalse($member->canAccessProject('form'));
    }

    public function test_kaman_member_can_only_access_kaman_bot_workspace(): void
    {
        $member = User::where('email', KamanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $authz = app(ChatbotAuthorizationService::class);
        $instances = $authz->instancesForUser($member);

        $this->assertCount(1, $instances);
        $this->assertTrue($instances->first()->hasKamanWhatsappIntegration());
        $this->assertSame(KamanWhatsappChatbotInstanceSeeder::INSTANCE_NAME, $instances->first()->name);

        $malan = ChatbotInstance::query()
            ->where('integration_type', 'malan')
            ->firstOrFail();

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $malan))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $instances->first()));
    }

    public function test_kaman_member_workspace_matches_malan_member_ui(): void
    {
        $member = User::where('email', KamanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $instance = app(ChatbotAuthorizationService::class)->firstAccessibleForUser($member);

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $instance))
            ->assertOk()
            ->assertSee($instance->name)
            ->assertSee(__('chatbot.workspace.nav_conversations'), false)
            ->assertSee(__('chatbot.workspace.nav_test'), false)
            ->assertSee(__('chatbot.workspace.nav_settings'), false)
            ->assertDontSee(__('chatbot.workspace.nav_campaigns'), false)
            ->assertDontSee(__('chatbot.choose.switch'));
    }

    public function test_kaman_member_owns_no_chatbot_instances(): void
    {
        $member = User::where('email', KamanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $member->id)->count());
    }
}
