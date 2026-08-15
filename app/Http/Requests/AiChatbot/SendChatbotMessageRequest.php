<?php

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;

class SendChatbotMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:10000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_chatbot_conversations,id'],
        ];
    }
}
