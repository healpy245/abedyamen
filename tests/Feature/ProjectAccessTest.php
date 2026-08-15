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
        'yamen@kaman.rest' => ['form', 'ai-chatbot'],
        'ahmad@kaman.rest' => ['form'],
        'mohamed@kaman.rest' => ['form', 'ai-chatbot'],
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
            'yamen@kaman.rest' => 'Yam123456@',
            'ahmad@kaman.rest' => 'Ahmad123',
            'mohamed@kaman.rest' => 'mohamed123@',
        ];

        foreach ($credentials as $email => $password) {
            $this->post('/login', ['email' => $email, 'password' => $password])
                ->assertRedirect(route('home'));

            $this->assertAuthenticatedAs(User::where('email', $email)->first());
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
            $this->get($project->url())->assertRedirect('/login');
        }
    }

    public function test_public_routes_stay_reachable_without_login(): void
    {
        $this->get(route('landing.show'))->assertOk();
    }

    public function test_welcome_page_lists_only_the_projects_a_user_can_open(): void
    {
        $cases = [
            'ahmad@kaman.rest' => [
                'visible' => [__('projects.form.label')],
                'hidden' => [__('projects.ai-chatbot.label')],
            ],
            'mohamed@kaman.rest' => [
                'visible' => [__('projects.form.label'), __('projects.ai-chatbot.label')],
                'hidden' => [],
            ],
            'yamen@kaman.rest' => [
                'visible' => [__('projects.form.label'), __('projects.ai-chatbot.label')],
                'hidden' => [],
            ],
        ];

        foreach ($cases as $email => $expected) {
            $user = User::where('email', $email)->firstOrFail();
            $response = $this->actingAs($user)->get(route('home'))->assertOk();

            foreach ($expected['visible'] as $label) {
                $response->assertSee($label);
            }

            foreach ($expected['hidden'] as $label) {
                $response->assertDontSee($label);
            }
        }
    }

    public function test_only_admins_see_and_reach_administration(): void
    {
        $admin = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $member = User::where('email', 'mohamed@kaman.rest')->firstOrFail();

        $this->actingAs($admin)->get(route('home'))->assertDontSee('Chatbot Settings');
        $this->actingAs($member)->get(route('home'))->assertDontSee('Chatbot Settings');

        $this->actingAs($admin)->get(route('ai-chatbot.admin.settings.edit'))->assertOk();
        $this->actingAs($member)->get(route('ai-chatbot.admin.settings.edit'))->assertForbidden();
    }

    public function test_a_user_with_no_grants_is_locked_out_but_not_broken(): void
    {
        $stranger = User::factory()->create(['is_admin' => false, 'projects' => []]);

        $this->actingAs($stranger)->get(route('home'))
            ->assertOk()
            ->assertSee(__('workspace.no_tools_title'));

        foreach (Project::cases() as $project) {
            $this->actingAs($stranger)->get($project->url())->assertForbidden();
        }
    }
}
