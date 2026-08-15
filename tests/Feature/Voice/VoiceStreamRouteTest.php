<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceStreamRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);
    }

    public function test_voice_stream_requires_authentication(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);

        $this->postJson(route('ai-chatbot.instances.voice.stream', $instance), [
            'message' => 'مرحبا',
        ])->assertUnauthorized();
    }

    public function test_voice_stream_validates_message(): void
    {
        $user = User::where('email', 'yamen@kaman.rest')->firstOrFail();
        $instance = ChatbotInstance::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.stream', $instance), [])
            ->assertStatus(422);
    }
}
