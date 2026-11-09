<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DesignConceptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_previews_are_separate_from_the_today_page(): void
    {
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)->get(route('today'))
            ->assertRedirect(route('design.modern-light'));

        foreach (['command', 'pro', 'bento'] as $concept) {
            $this->actingAs($yamen)->get(route('design.show', $concept))
                ->assertOk()
                ->assertSee('kaman.png', false)
                ->assertSee(__('projects.form.label'))
                ->assertSee(__('projects.kaman-support.label'));
        }
    }

    public function test_modern_light_preview_uses_real_doors_and_leaves_today_alone(): void
    {
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)->get(route('design.modern-light'))
            ->assertOk()
            ->assertSee('kaman.png', false)
            ->assertSee(__('app.hub_title'))
            ->assertSee(__('app.overview_dashboard'))
            ->assertSee(__('app.overview_restaurants'))
            ->assertSee('https://boarding.mfitgroup.com', false)
            ->assertSee(__('app.hub_support_nav'))
            ->assertSee(__('app.hub_dev'))
            ->assertSee(__('app.hub_finance'))
            ->assertSee(route('kaman-support.index'), false)
            ->assertSee(route('app-development.reports.index'), false);

        $this->actingAs($yamen)->get(route('workspace.agents'))
            ->assertOk()
            ->assertSee(__('app.agents_leads'))
            ->assertSee(__('app.agents_calendar'))
            ->assertDontSee('data-ag-nav="clients"', false)
            ->assertDontSee(__('app.hub_closed'));

        $this->actingAs($yamen)->get(route('workspace.agents', ['page' => 'clients']))
            ->assertRedirect(route('workspace.overview', ['page' => 'restaurants']));

        Http::fake(['https://kaman.rest/*' => Http::response(['data' => ['companies' => [], 'areas' => []]])]);
        $this->actingAs($yamen)->get(route('workspace.finance.dashboard'))
            ->assertOk()
            ->assertSee(__('finance.title'))
            ->assertDontSee(__('app.hub_closed'));

        $this->actingAs($yamen)->get(route('today'))
            ->assertRedirect(route('design.modern-light'));
    }

    public function test_a_chatbot_account_does_not_open_the_previews(): void
    {
        $malan = User::factory()->create([
            'email' => 'malan@kaman.rest',
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);

        $this->actingAs($malan)
            ->get(route('design.index'))
            ->assertRedirect($malan->afterLoginUrl());

        $this->actingAs($malan)
            ->get(route('design.modern-light'))
            ->assertRedirect($malan->afterLoginUrl());

        $this->actingAs($malan)
            ->get(route('workspace.agents'))
            ->assertRedirect($malan->afterLoginUrl());
    }

    public function test_agents_workspace_boots_companies_from_kaman(): void
    {
        Cache::forget('kaman.rest.companies.v2');
        Cache::forget('kaman.rest.company-areas');
        Http::fake([
            'kaman.rest/api/companies' => Http::response([
                'success' => true,
                'data' => [
                    'companies' => [[
                        'id' => 'abc',
                        'name' => 'Gcp',
                        'subdomain' => 'gcp',
                        'full_path' => 'https://gcp.kaman.rest',
                        'settings' => [
                            'color' => '#831515',
                            'address' => 'كفرقاسم',
                            'logo' => 'https://admin.kaman.rest/storage/logos/x.png',
                            'working_hours' => '{"Sunday":{"from":"10:00","to":"23:00"}}',
                        ],
                    ], [
                        'id' => 'ita',
                        'name' => 'italiano',
                        'subdomain' => 'italiano',
                        'full_path' => 'https://italiano.kaman.rest',
                        'settings' => ['address' => ''],
                    ]],
                ],
            ]),
            'kaman.rest/api/company-areas' => Http::response([
                'success' => true,
                'data' => [
                    'areas' => [[
                        'id' => 'area-kq',
                        'name_ar' => 'كفر قاسم',
                        'name_en' => 'Kufur Qasem',
                        'name_he' => 'כפר קאסם',
                    ], [
                        'id' => 'area-tira',
                        'name_ar' => 'طيره',
                        'name_en' => 'Tira',
                        'name_he' => 'טירה',
                    ]],
                ],
            ]),
        ]);

        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->get(route('workspace.overview', ['page' => 'restaurants']))
            ->assertOk()
            ->assertSee('Gcp', false)
            ->assertSee('gcp.kaman.rest', false)
            ->assertSee('admin.kaman.rest', false)
            ->assertSee('Kufur Qasem', false)
            ->assertSee('"cityId":"area-kq"', false)
            ->assertSee('"id":"ita"', false)
            ->assertDontSee('"cityId":"area-tira"', false)
            ->assertDontSee('Shawarma HaNasi', false);
    }
}
