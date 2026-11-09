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
        'yamen@kaman.rest' => ['form', 'app-development', 'kaman-support'],
        'burhan@kaman.rest' => ['form', 'app-development'],
        'ahmad@kaman.rest' => ['form'],
        'mohamed@kaman.rest' => ['form'],
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
            'moaz@kaman.rest' => 'Moaz123@',
            'connections@kaman.rest' => 'Connections123@',
        ];

        foreach ($credentials as $email => $password) {
            $user = User::where('email', $email)->firstOrFail();

            $this->post('/login', ['email' => $email, 'password' => $password])
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
            $this->get($project->url())->assertRedirect('/login');
        }
    }

    public function test_a_chatbot_only_account_cannot_sign_in_here(): void
    {
        $chatbot = User::factory()->create([
            'email' => 'malan@kaman.rest',
            'password' => 'Malan123@',
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);

        $this->post('/login', [
            'email' => 'malan@kaman.rest',
            'password' => 'Malan123@',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue($chatbot->isChatbotOnly());
    }

    public function test_public_routes_stay_reachable_without_login(): void
    {
        $this->get(route('landing.show'))->assertOk();
    }

    public function test_workers_land_on_the_overview(): void
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
    }

    public function test_form_pages_do_not_offer_chatbot_settings(): void
    {
        $admin = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $member = User::where('email', 'mohamed@kaman.rest')->firstOrFail();

        $this->actingAs($admin)->get(route('form.index'))->assertDontSee('Chatbot Settings');
        $this->actingAs($member)->get(route('form.index'))->assertDontSee('Chatbot Settings');
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
