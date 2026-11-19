<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketContactTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $details): SupportTicket
    {
        return SupportTicket::query()->create([
            'public_id' => 'KAM-'.strtoupper(fake()->bothify('??####')),
            'restaurant' => 'thex',
            'restaurant_name' => 'The X',
            'summary' => 'Kitchen printer stopped',
            'details' => $details,
            'status' => 'open',
        ]);
    }

    public function test_a_number_in_the_ticket_becomes_a_whatsapp_button(): void
    {
        $user = User::factory()->create(['projects' => ['kaman-support']]);
        $ticket = $this->ticket('Nothing prints. Call Rami on 052-123-4567 please');

        $this->actingAs($user)->getJson('/kaman-support/inbox/feed')
            ->assertOk()
            ->assertJsonPath('tickets.0.contact.whatsapp', 'https://wa.me/972521234567')
            ->assertJsonPath('tickets.0.contact.phone', '052-123-4567')
            ->assertJsonPath('labels.whatsapp', __('kaman-support.whatsapp'));

        $this->actingAs($user)->getJson('/kaman-support/tickets/'.$ticket->public_id)
            ->assertOk()
            ->assertJsonPath('ticket.contact.whatsapp', 'https://wa.me/972521234567')
            ->assertJsonPath('copy.contact', __('kaman-support.contact'));

        $this->actingAs($user)->get('/kaman-support/inbox')
            ->assertOk()
            ->assertSee('href="https://wa.me/972521234567"', false);
    }

    public function test_a_ticket_without_a_number_has_no_button(): void
    {
        $user = User::factory()->create(['projects' => ['kaman-support']]);
        $ticket = $this->ticket('The screen froze at 14:30 on order 123456789');

        $this->actingAs($user)->getJson('/kaman-support/inbox/feed')->assertOk()->assertJsonPath('tickets.0.contact', null);
        $this->actingAs($user)->getJson('/kaman-support/tickets/'.$ticket->public_id)->assertOk()->assertJsonPath('ticket.contact', null);
        $this->actingAs($user)->get('/kaman-support/inbox')->assertOk()->assertDontSee('wa.me', false);
    }
}
