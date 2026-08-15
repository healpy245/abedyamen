<?php

namespace Database\Factories;

use App\Enums\Voice\VoiceCallStatus;
use App\Enums\Voice\VoiceProviderName;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use App\Models\Voice\VoiceCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceCall>
 */
class VoiceCallFactory extends Factory
{
    protected $model = VoiceCall::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chatbot_instance_id' => ChatbotInstance::factory(),
            'provider' => VoiceProviderName::Fake->value,
            'provider_call_id' => 'fake_'.fake()->uuid(),
            'caller_number' => fake()->e164PhoneNumber(),
            'called_number' => null,
            'status' => VoiceCallStatus::Active,
            'started_at' => now(),
            'answered_at' => now(),
            'metadata' => ['simulated' => true],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => VoiceCallStatus::Completed,
            'ended_at' => now(),
            'duration_seconds' => 30,
        ]);
    }
}
