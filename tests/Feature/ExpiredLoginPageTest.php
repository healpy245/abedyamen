<?php

namespace Tests\Feature;

use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpiredLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('csrf.enforce', true);
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_stale_login_page_still_signs_the_user_in(): void
    {
        $this->post('/login', [
            '_token' => 'stale-token',
            'email' => 'yamen@kaman.rest',
            'password' => 'yam1234@',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->get(route('home'))->assertOk();
    }

    public function test_stale_login_page_with_a_wrong_password_returns_to_sign_in(): void
    {
        $this->from(route('login'))
            ->post('/login', [
                '_token' => 'stale-token',
                'email' => 'yamen@kaman.rest',
                'password' => 'not-the-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_other_expired_posts_leave_the_error_page(): void
    {
        $this->from(route('login'))
            ->post('/logout', ['_token' => 'stale-token'])
            ->assertRedirect(route('login'));
    }

    public function test_csrf_token_endpoint_is_available_before_sign_in(): void
    {
        $this->getJson(route('csrf.token'))
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
