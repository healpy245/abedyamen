<?php

namespace Tests\Feature;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every internal page must render and wear the Kaman design.
 *
 * The guard that matters is assertDontSee('bg-slate-950'): it fails loudly if a
 * page is ever reverted to, or added with, the old dark theme.
 */
class KamanDesignTest extends TestCase
{
    use RefreshDatabase;

    private const KAMAN_STYLESHEET = 'css/kaman.css';

    private const DARK_THEME_MARKER = 'bg-slate-950';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->seed(WorkspaceUserSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function chatbotInstance(): ChatbotInstance
    {
        // Provisioned on first visit to the studio.
        $this->actingAs($this->admin())->get(route('ai-chatbot.index'));

        return ChatbotInstance::query()->firstOrFail();
    }

    public function test_login_page_renders_in_kaman_design(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(self::KAMAN_STYLESHEET)
            ->assertSee('Sign in')
            ->assertDontSee(self::DARK_THEME_MARKER);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectPageProvider(): array
    {
        return [
            'welcome' => ['home'],
            'form' => ['form.index'],
            'whatsapp bot' => ['whatsapp.bot.index'],
            'chatbot settings' => ['ai-chatbot.admin.settings.edit'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('projectPageProvider')]
    public function test_page_renders_in_kaman_design(string $routeName): void
    {
        $this->actingAs($this->admin())
            ->get(route($routeName))
            ->assertOk()
            ->assertSee(self::KAMAN_STYLESHEET)
            ->assertDontSee(self::DARK_THEME_MARKER);
    }

    public function test_ai_chatbot_studio_pages_render_in_kaman_design(): void
    {
        $instance = $this->chatbotInstance();

        // The members page is only reachable when the instance opts into
        // storing members; otherwise it legitimately 404s.
        $instance->forceFill(['stores_members' => true])->save();

        $pages = [
            route('ai-chatbot.instances.show', $instance),
            route('ai-chatbot.instances.edit', $instance),
            route('ai-chatbot.instances.members.index', $instance),
        ];

        foreach ($pages as $url) {
            $this->actingAs($this->admin())
                ->get($url)
                ->assertOk()
                ->assertSee(self::KAMAN_STYLESHEET)
                ->assertDontSee(self::DARK_THEME_MARKER);
        }
    }

    public function test_topbar_only_offers_projects_the_user_can_open(): void
    {
        $ahmad = User::where('email', 'ahmad@kaman.rest')->firstOrFail();

        // Ahmad has a single project, so no switcher is rendered at all.
        $this->actingAs($ahmad)->get(route('form.index'))
            ->assertOk()
            ->assertDontSee('WhatsApp Bot')
            ->assertDontSee('AI Chatbot Studio');

        $mohamed = User::where('email', 'mohamed@kaman.rest')->firstOrFail();

        $this->actingAs($mohamed)->get(route('whatsapp.bot.index'))
            ->assertOk()
            ->assertSee('Restaurant Form')
            ->assertDontSee('AI Chatbot Studio');
    }

    public function test_every_page_offers_a_way_to_sign_out(): void
    {
        foreach (['home', 'form.index', 'whatsapp.bot.index'] as $routeName) {
            $this->actingAs($this->admin())
                ->get(route($routeName))
                ->assertOk()
                ->assertSee(route('logout'));
        }
    }
}
