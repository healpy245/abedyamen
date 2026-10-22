<?php

namespace Tests\Feature;

use App\Enums\Project;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Locks in the workspace access matrix: one login, per-project authorisation.
 */
class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Who may open what. Mirrors WorkspaceUserSeeder.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'yamen@kaman.rest' => ['form', 'ai-chatbot', 'app-development', 'kaman-support'],
        'burhan@kaman.rest' => ['form', 'ai-chatbot', 'app-development'],
        'ahmad@kaman.rest' => ['form'],
        'mohamed@kaman.rest' => ['form', 'ai-chatbot'],
        'malan@kaman.rest' => ['ai-chatbot'],
        'kaman@kaman.rest' => ['ai-chatbot'],
        'speedcom@kaman.rest' => ['ai-chatbot'],
        'ibrahim@kaman-workspace.com' => ['ai-chatbot'],
        'ahmadessa@kaman.rest' => ['form', 'app-development'],
        'moaz@kaman.rest' => ['kaman-support'],
        'connections@kaman.rest' => ['kaman-support'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_each_seeded_user_can_sign_in_with_their_password(): void
    {
        $credentials = [
            'yamen@kaman.rest' => 'yam1234@',
            'burhan@kaman.rest' => 'Burhan123@',
            'ahmad@kaman.rest' => 'Ahmad123',
            'mohamed@kaman.rest' => 'mohamed123@',
            'malan@kaman.rest' => 'Malan123@',
            'kaman@kaman.rest' => 'Kaman123@',
            'speedcom@kaman.rest' => 'Speedcom123@',
            'ibrahim@kaman-workspace.com' => 'Ibrahim123@',
            'moaz@kaman.rest' => 'Moaz123@',
            'connections@kaman.rest' => 'Connections123@',
        ];

        foreach ($credentials as $email => $password) {
            $user = User::where('email', $email)->firstOrFail();
            $path = $user->isChatbotOnly() ? route('chatbots.login') : '/login';

            $this->post($path, ['email' => $email, 'password' => $password])
                ->assertRedirect($user->afterLoginUrl());

            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    public function test_access_matrix_is_enforced_on_project_routes(): void
    {
        foreach (self::MATRIX as $email => $allowed) {
            $user = User::where('email', $email)->firstOrFail();

            foreach (Project::cases() as $project) {
                $response = $this->actingAs($user)->get($project->url());

                if (in_array($project->value, $allowed, true)) {
                    $this->assertLessThan(
                        400,
                        $response->getStatusCode(),
                        "{$email} should reach {$project->value}, got {$response->getStatusCode()}"
                    );
                } else {
                    $response->assertForbidden();
                }
            }
        }
    }

    public function test_guests_are_redirected_to_login_for_every_project(): void
    {
        foreach (Project::cases() as $project) {
            $expected = $project === Project::AiChatbot ? route('chatbots.login') : '/login';
            $this->get($project->url())->assertRedirect($expected);
        }
    }

    public function test_a_chatbot_account_cannot_use_the_kaman_login(): void
    {
        $this->post('/login', [
            'email' => 'malan@kaman.rest',
            'password' => 'Malan123@',
        ])->assertRedirect(route('chatbots.login'));

        $this->assertGuest();

        $this->post(route('chatbots.login'), [
            'email' => 'yamen@kaman.rest',
            'password' => 'yam1234@',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_public_routes_stay_reachable_without_login(): void
    {
        $this->get(route('landing.show'))->assertOk();
    }

    public function test_workers_land_on_the_overview_and_chatbot_accounts_stay_in_chat(): void
    {
        $workers = [
            'ahmad@kaman.rest',
            'mohamed@kaman.rest',
            'yamen@kaman.rest',
            'burhan@kaman.rest',
            'ahmadessa@kaman.rest',
            'moaz@kaman.rest',
            'connections@kaman.rest',
        ];

        foreach ($workers as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)
                ->get($user->afterLoginUrl())
                ->assertOk()
                ->assertSee(__('app.hub_title'));
        }

        $chatbots = [
            'malan@kaman.rest',
            'kaman@kaman.rest',
            'speedcom@kaman.rest',
            'ibrahim@kaman-workspace.com',
        ];

        foreach ($chatbots as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user)
                ->followingRedirects()
                ->get($user->afterLoginUrl())
                ->assertOk()
                ->assertSee(__('projects.ai-chatbot.label'));
        }
    }

    public function test_only_admins_see_and_reach_administration(): void
    {
        $admin = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $member = User::where('email', 'mohamed@kaman.rest')->firstOrFail();

        $this->actingAs($admin)->get(route('form.index'))->assertDontSee('Chatbot Settings');
        $this->actingAs($member)->get(route('form.index'))->assertDontSee('Chatbot Settings');

        $this->actingAs($admin)->get(route('ai-chatbot.admin.settings.edit'))->assertOk();
        $this->actingAs($member)->get(route('ai-chatbot.admin.settings.edit'))->assertForbidden();
    }

    public function test_a_user_with_no_grants_is_locked_out_but_not_broken(): void
    {
        $stranger = User::factory()->create(['is_admin' => false, 'projects' => []]);

        $this->actingAs($stranger)->get(route('home'))
            ->assertRedirect(route('login'));

        foreach (Project::cases() as $project) {
            $this->actingAs($stranger)->get($project->url())->assertForbidden();
        }
    }
}
