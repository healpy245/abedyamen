<?php

declare(strict_types=1);

namespace Tests\Feature\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAppTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ticket_requires_app_types_and_filters_by_them(): void
    {
        $qa = $this->makeQa();

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Print fails',
            'priority' => AppDevelopmentTicketPriority::High->value,
            'description' => 'Receipt does not print',
        ])->assertSessionHasErrors('app_types');

        $this->actingAs($qa)->post(route('app-development.tickets.store'), [
            'title' => 'Print fails',
            'priority' => AppDevelopmentTicketPriority::High->value,
            'app_types' => [AppDevelopmentAppType::Printing->value, AppDevelopmentAppType::Web->value],
            'description' => 'Receipt does not print',
        ])->assertRedirect();

        $ticket = AppDevelopmentTicket::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [AppDevelopmentAppType::Printing->value, AppDevelopmentAppType::Web->value],
            collect($ticket->appTypes())->map->value->all(),
        );

        $this->actingAs($qa)
            ->get(route('app-development.tickets.index', ['app_type' => [AppDevelopmentAppType::Printing->value]]))
            ->assertOk()
            ->assertSee($ticket->ticket_number);

        $this->actingAs($qa)
            ->get(route('app-development.tickets.index', ['app_type' => [AppDevelopmentAppType::Cashier->value]]))
            ->assertOk()
            ->assertDontSee($ticket->ticket_number);
    }

    public function test_inline_app_types_can_be_updated(): void
    {
        $qa = $this->makeQa();
        $ticket = AppDevelopmentTicket::query()->create([
            'ticket_number' => 'KAM-9999',
            'title' => 'Multi app bug',
            'description' => 'Desc',
            'type' => 'bug',
            'priority' => AppDevelopmentTicketPriority::Normal,
            'status' => 'open',
            'created_by' => $qa->id,
        ]);
        $ticket->syncAppTypes([AppDevelopmentAppType::Web->value]);

        $this->actingAs($qa)
            ->patchJson(route('app-development.tickets.app-types', $ticket), [
                'app_types' => [AppDevelopmentAppType::Cashier->value, AppDevelopmentAppType::KamanClient->value],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $ticket->refresh()->load('appTypeRows');
        $this->assertEqualsCanonicalizing(
            [AppDevelopmentAppType::Cashier->value, AppDevelopmentAppType::KamanClient->value],
            collect($ticket->appTypes())->map->value->all(),
        );
    }

    private function makeQa(): User
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'projects' => [Project::AppDevelopment->value],
        ]);
        AppDevelopmentMember::query()->create([
            'user_id' => $user->id,
            'role' => AppDevelopmentRole::Qa,
        ]);

        return $user;
    }
}
