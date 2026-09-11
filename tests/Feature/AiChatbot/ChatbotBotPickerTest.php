<?php

namespace Tests\Feature\AiChatbot;

use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Database\Seeders\KamanCompanyMemberSeeder;
use Database\Seeders\KamanWhatsappChatbotInstanceSeeder;
use Database\Seeders\MalanCompanyMemberSeeder;
use Database\Seeders\SallyMalanChatbotInstanceSeeder;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotBotPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceUserSeeder::class);
        $this->seed(SallyMalanChatbotInstanceSeeder::class);
        $this->seed(SpeedcomChatbotInstanceSeeder::class);
        $this->seed(KamanWhatsappChatbotInstanceSeeder::class);
        $this->seed(MalanCompanyMemberSeeder::class);
        $this->seed(KamanCompanyMemberSeeder::class);
    }

    public function test_yamen_chooses_a_bot_then_enters_workspace_not_studio(): void
    {
        $yamen = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $authz = app(ChatbotAuthorizationService::class);
        $instances = $authz->instancesForUser($yamen);
        $malan = $instances->firstWhere('integration_type', 'malan');
        $speedcom = $instances->firstWhere('integration_type', 'speedcom');
        $kaman = $instances->firstWhere('integration_type', 'kaman_whatsapp');

        $this->assertNotNull($malan);
        $this->assertNotNull($speedcom);
        $this->assertNotNull($kaman);
        $this->assertGreaterThanOrEqual(3, $instances->count());

        $this->actingAs($yamen)
            ->get(route('ai-chatbot.index'))
            ->assertOk()
            ->assertSee(__('chatbot.choose.title'))
            ->assertSee($malan->name)
            ->assertSee($speedcom->name)
            ->assertSee($kaman->name)
            ->assertSee(route('ai-chatbot.workspace.conversations', $malan), false)
            ->assertSee(route('ai-chatbot.workspace.conversations', $speedcom), false)
            ->assertSee(route('ai-chatbot.workspace.conversations', $kaman), false)
            ->assertDontSee(__('chatbot.new_chat'));

        $this->actingAs($yamen)
            ->get(route('ai-chatbot.workspace.conversations', $malan))
            ->assertOk()
            ->assertSee($malan->name)
            ->assertSee(__('chatbot.workspace.nav_campaigns'), false)
            ->assertSee(__('chatbot.choose.switch'))
            ->assertSee(route('ai-chatbot.index'), false);

        $this->actingAs($yamen)
            ->get(route('ai-chatbot.workspace.conversations', $kaman))
            ->assertOk()
            ->assertSee($kaman->name)
            ->assertDontSee(__('chatbot.workspace.nav_campaigns'), false)
            ->assertSee(__('chatbot.choose.switch'));

        $this->actingAs($yamen)
            ->get(route('ai-chatbot.workspace.conversations', $speedcom))
            ->assertOk()
            ->assertSee($speedcom->name)
            ->assertSee(__('chatbot.workspace.nav_campaigns'), false);
    }

    public function test_malan_member_still_skips_picker_into_malan_workspace(): void
    {
        $member = User::where('email', MalanCompanyMemberSeeder::MEMBER_EMAIL)->firstOrFail();
        $instance = app(ChatbotAuthorizationService::class)->firstAccessibleForUser($member);

        $this->actingAs($member)
            ->get(route('ai-chatbot.index'))
            ->assertRedirect(route('ai-chatbot.workspace.conversations', $instance));
    }
}
