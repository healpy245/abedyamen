<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\MenuAgentChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_is_created_then_updated_and_listed(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'projects' => ['kaman-support']]);

        $created = $this->actingAs($user)->postJson(route('kaman-support.menu.chats.store'), [
            'subdomain' => 'asado',
            'messages' => [['role' => 'user', 'content' => 'Add a   pizza category']],
        ])->assertOk()->json();

        $this->assertSame('Add a pizza category', $created['title']);

        $this->actingAs($user)->postJson(route('kaman-support.menu.chats.update', $created['id']), [
            'subdomain' => 'asado',
            'messages' => [
                ['role' => 'user', 'content' => 'Add a pizza category'],
                ['role' => 'assistant', 'content' => 'Done.'],
            ],
        ])->assertOk()->assertJsonPath('id', $created['id']);

        $this->assertSame(1, MenuAgentChat::query()->count());
        $this->actingAs($user)->getJson(route('kaman-support.menu.chats'))->assertOk()->assertJsonCount(1, 'chats');
        $this->actingAs($user)->getJson(route('kaman-support.menu.chats.show', $created['id']))
            ->assertOk()
            ->assertJsonCount(2, 'messages');
    }

    public function test_users_cannot_open_or_delete_each_others_chats(): void
    {
        $owner = User::factory()->create(['is_admin' => true, 'projects' => ['kaman-support']]);
        $other = User::factory()->create(['is_admin' => true, 'projects' => ['kaman-support']]);
        $chat = MenuAgentChat::query()->create([
            'user_id' => $owner->id,
            'subdomain' => 'asado',
            'title' => 'Private',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);

        $this->actingAs($other)->getJson(route('kaman-support.menu.chats.show', $chat))->assertNotFound();
        $this->actingAs($other)->deleteJson(route('kaman-support.menu.chats.destroy', $chat))->assertNotFound();
        $this->actingAs($other)->getJson(route('kaman-support.menu.chats'))->assertJsonCount(0, 'chats');

        $this->actingAs($owner)->deleteJson(route('kaman-support.menu.chats.destroy', $chat))->assertOk();
        $this->assertSame(0, MenuAgentChat::query()->count());
    }
}
