<?php

namespace Tests\Feature\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Database\Seeders\KamanWhatsappChatbotInstanceSeeder;
use Database\Seeders\MalanCompanyMemberSeeder;
use Database\Seeders\SallyMalanChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MalanCompanyMemberAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SallyMalanChatbotInstanceSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);
        $this->seed(MalanCompanyMemberSeeder::class);
    }

    public function test_malan_member_has_only_chatbot_project(): void
    {
        $member = User::where('email', MalanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertFalse((bool) $member->is_admin);
        $this->assertSame(['ai-chatbot'], $member->projectKeys());
        $this->assertTrue($member->canAccessProject('ai-chatbot'));
        $this->assertFalse($member->canAccessProject('form'));
    }

    public function test_malan_member_can_only_access_malan_bot(): void
    {
        $member = User::where('email', MalanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $authz = app(ChatbotAuthorizationService::class);
        $instances = $authz->instancesForUser($member);

        $this->assertCount(1, $instances);
        $this->assertTrue($instances->first()->hasMalanIntegration());
        $this->assertSame(SallyMalanChatbotInstanceSeeder::INSTANCE_NAME, $instances->first()->name);

        $other = ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->firstOrFail();

        $this->actingAs($member)
            ->get(route('ai-chatbot.workspace.conversations', $other))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $instances->first()));
    }

    public function test_malan_member_owns_no_chatbot_instances(): void
    {
        $member = User::where('email', MalanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();

        $this->assertSame(0, ChatbotInstance::query()->where('user_id', $member->id)->count());
    }
}
