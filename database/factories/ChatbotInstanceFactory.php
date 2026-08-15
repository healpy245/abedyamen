<?php

namespace Database\Factories;

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatbotInstance>
 */
class ChatbotInstanceFactory extends Factory
{
    protected $model = ChatbotInstance::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'system_prompt' => 'You are a helpful AI assistant.',
            'stores_members' => false,
        ];
    }
}
