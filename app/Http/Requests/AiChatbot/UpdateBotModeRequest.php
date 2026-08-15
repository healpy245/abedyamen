<?php

namespace App\Http\Requests\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBotModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bot_mode' => ['required', 'string', Rule::in([
                ChatbotConversation::BOT_MODE_ACTIVE,
                ChatbotConversation::BOT_MODE_PAUSED,
                ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
            ])],
        ];
    }
}
