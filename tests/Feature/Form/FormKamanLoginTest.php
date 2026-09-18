<?php

declare(strict_types=1);

namespace Tests\Feature\Form;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FormKamanLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_to_kaman_dev_stores_dev_base_url(): void
    {
        Http::fake([
            'https://thex.kaman.dev/api/manager/login' => Http::response(['token' => 'dev-token'], 200),
        ]);

        $this->actingAs($this->formUser())
            ->postJson(route('form.full-ai.login'), [
                'subdomain' => 'thex',
                'username' => 'thex@kaman.rest',
                'password' => 'secret',
                'environment' => 'dev',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('environment', 'dev')
            ->assertJsonPath('host', 'https://thex.kaman.dev')
            ->assertSessionHas('full_ai_kaman_token', 'dev-token')
            ->assertSessionHas('full_ai_kaman_base_url', 'https://thex.kaman.dev/api/manager');

        Http::assertSent(fn ($request) => $request->url() === 'https://thex.kaman.dev/api/manager/login'
            && $request['email'] === 'thex@kaman.rest');
    }

    public function test_login_to_kaman_rest_stores_rest_base_url(): void
    {
        Http::fake([
            'https://thex.kaman.rest/api/manager/login' => Http::response(['token' => 'rest-token'], 200),
        ]);

        $this->actingAs($this->formUser())
            ->postJson(route('form.full-ai.login'), [
                'subdomain' => 'thex',
                'username' => 'thex@kaman.rest',
                'password' => 'secret',
                'environment' => 'rest',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertSessionHas('full_ai_kaman_base_url', 'https://thex.kaman.rest/api/manager');
    }

    public function test_auth_status_is_environment_specific(): void
    {
        $this->actingAs($this->formUser())
            ->withSession([
                'full_ai_kaman_token' => 'rest-token',
                'full_ai_kaman_base_url' => 'https://thex.kaman.rest/api/manager',
            ])
            ->postJson(route('form.full-ai.auth-status'), [
                'subdomain' => 'thex',
                'environment' => 'dev',
            ])
            ->assertOk()
            ->assertJsonPath('authenticated', false);

        $this->actingAs($this->formUser())
            ->withSession([
                'full_ai_kaman_token' => 'rest-token',
                'full_ai_kaman_base_url' => 'https://thex.kaman.rest/api/manager',
            ])
            ->postJson(route('form.full-ai.auth-status'), [
                'subdomain' => 'thex',
                'environment' => 'rest',
            ])
            ->assertOk()
            ->assertJsonPath('authenticated', true);
    }

    private function formUser(): User
    {
        $this->seed(WorkspaceUserSeeder::class);

        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }
}
