<?php

namespace Tests\Feature\Voice;

use App\Enums\Voice\VoiceCallMessageRole;
use App\Enums\Voice\VoiceCallStatus;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\AiChatbotService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class VoiceCallSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->seed(WorkspaceUserSeeder::class);
    }

    private function chatbotUser(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function otherUser(): User
    {
        return User::where('email', 'ahmad@kaman.rest')->firstOrFail();
    }

    private function instanceFor(User $user): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $user->id,
            'name' => 'Support Bot',
        ]);
    }

    public function test_authenticated_user_can_open_simulator_for_their_instance(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->get(route('ai-chatbot.instances.show', $instance))
            ->assertOk()
            ->assertSee(__('chatbot.composer_placeholder'));
    }

    public function test_user_cannot_open_another_users_instance(): void
    {
        $owner = $this->chatbotUser();
        $stranger = User::factory()->create([
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);
        $instance = $this->instanceFor($owner);

        $this->actingAs($stranger)
            ->get(route('ai-chatbot.instances.show', $instance))
            ->assertForbidden();
    }

    public function test_user_can_start_a_phone_mode_call_with_voice_profile(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $this->actingAs($user)
            ->post(route('ai-chatbot.instances.voice.start', $instance), [
                'caller_number' => '+966500000001',
                'interaction_mode' => 'phone',
                'voice_profile' => 'man',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('voice_calls', [
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'status' => VoiceCallStatus::Active->value,
        ]);

        $call = VoiceCall::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('phone', $call->metadata['interaction_mode'] ?? null);
        $this->assertSame('man', $call->metadata['voice_profile'] ?? null);
    }

    public function test_user_can_start_a_fake_call(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $response = $this->actingAs($user)
            ->post(route('ai-chatbot.instances.voice.start', $instance), [
                'caller_number' => '+966500000000',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('voice_calls', [
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'provider' => 'fake',
            'caller_number' => '+966500000000',
            'status' => VoiceCallStatus::Active->value,
        ]);
    }

    public function test_caller_and_assistant_messages_are_stored_using_chatbot_service(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);

        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'status' => VoiceCallStatus::Active,
        ]);

        $mock = Mockery::mock(AiChatbotService::class);
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($passedUser, $passedInstance, $message, $conversationId) use ($user, $instance, $call) {
                return $passedUser->is($user)
                    && $passedInstance->is($instance)
                    && $message === 'مرحبا'
                    && $conversationId === $call->chatbot_conversation_id;
            })
            ->andReturnUsing(function () use ($user, $instance) {
                $conversation = \App\Models\AiChatbot\ChatbotConversation::create([
                    'user_id' => $user->id,
                    'instance_id' => $instance->id,
                    'title' => 'Voice call',
                ]);

                $userMessage = $conversation->messages()->create([
                    'role' => 'user',
                    'message' => 'مرحبا',
                ]);

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'message' => 'أهلاً بك! كيف يمكنني مساعدتك؟',
                ]);

                return [
                    'conversation' => $conversation,
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                ];
            });

        $this->app->instance(AiChatbotService::class, $mock);

        $response = $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.message', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), [
                'message' => 'مرحبا',
            ]);

        $response->assertOk()
            ->assertJsonPath('assistant_message.content', 'أهلاً بك! كيف يمكنني مساعدتك؟');

        $this->assertDatabaseHas('voice_call_messages', [
            'voice_call_id' => $call->id,
            'role' => VoiceCallMessageRole::Caller->value,
            'content' => 'مرحبا',
        ]);

        $this->assertDatabaseHas('voice_call_messages', [
            'voice_call_id' => $call->id,
            'role' => VoiceCallMessageRole::Assistant->value,
            'content' => 'أهلاً بك! كيف يمكنني مساعدتك؟',
        ]);
    }

    public function test_user_cannot_send_to_another_users_call(): void
    {
        $owner = $this->chatbotUser();
        $stranger = User::factory()->create([
            'is_admin' => false,
            'projects' => ['ai-chatbot'],
        ]);
        $instance = $this->instanceFor($owner);
        $call = VoiceCall::factory()->create([
            'user_id' => $owner->id,
            'chatbot_instance_id' => $instance->id,
            'status' => VoiceCallStatus::Active,
        ]);

        $this->actingAs($stranger)
            ->postJson(route('ai-chatbot.instances.voice.message', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), ['message' => 'hello'])
            ->assertForbidden();
    }

    public function test_completed_calls_reject_new_messages(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->completed()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('ai-chatbot.instances.voice.message', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]), ['message' => 'hello'])
            ->assertStatus(422);
    }

    public function test_ending_a_call_sets_status_and_timestamps(): void
    {
        $user = $this->chatbotUser();
        $instance = $this->instanceFor($user);
        $call = VoiceCall::factory()->create([
            'user_id' => $user->id,
            'chatbot_instance_id' => $instance->id,
            'status' => VoiceCallStatus::Active,
        ]);

        $this->actingAs($user)
            ->post(route('ai-chatbot.instances.voice.end', [
                'instance' => $instance,
                'voiceCall' => $call,
            ]))
            ->assertRedirect();

        $call->refresh();

        $this->assertSame(VoiceCallStatus::Completed, $call->status);
        $this->assertNotNull($call->ended_at);
        $this->assertNotNull($call->duration_seconds);
    }
}
