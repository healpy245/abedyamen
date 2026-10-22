<?php

namespace Tests\Feature\OfficeVision;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeVisionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_workspace_pages_do_not_mention_the_camera_page(): void
    {
        $admin = User::where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($admin)->get(route('form.index'))
            ->assertOk()
            ->assertDontSee('OfficeVision')
            ->assertDontSee('/internal/4545');
    }

    public function test_the_path_stays_locked_until_the_static_code(): void
    {
        $this->get('/internal/4545')
            ->assertOk()
            ->assertDontSee('/api/officevision');

        $this->post('/internal/4545', ['code' => '0000'])
            ->assertRedirect('/internal/4545');

        $this->get('/internal/4545')->assertDontSee('/api/officevision');

        $this->post('/internal/4545', ['code' => '4545'])
            ->assertRedirect('/internal/4545');

        $this->get('/internal/4545')
            ->assertOk()
            ->assertSee('/api/officevision');
    }
}
