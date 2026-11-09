<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_a_kaman_worker_lands_on_the_overview(): void
    {
        $ahmad = User::query()->where('email', 'ahmad@kaman.rest')->firstOrFail();

        $this->post('/login', [
            'email' => 'ahmad@kaman.rest',
            'password' => 'Ahmad123',
        ])->assertRedirect(route('design.modern-light'));

        $this->actingAs($ahmad)
            ->get(route('today'))
            ->assertRedirect(route('design.modern-light'));

        $this->actingAs($ahmad)
            ->get(route('design.modern-light'))
            ->assertOk()
            ->assertSee(__('app.hub_title'))
            ->assertSee(__('app.overview_dashboard'));
    }

    public function test_a_chatbot_account_never_opens_the_kaman_today_page(): void
    {
        $malan = User::factory()->create([
            'email' => 'malan@kaman.rest',
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);

        $this->actingAs($malan)
            ->get(route('today'))
            ->assertRedirect($malan->afterLoginUrl());
    }
}
