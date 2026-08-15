<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class CreateRealtimeSessionRequest extends FormRequest
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
            'conversation_id' => ['nullable', 'integer', 'exists:ai_chatbot_conversations,id'],
            'reconnect' => ['nullable', 'boolean'],
        ];
    }

    public function conversationId(): ?int
    {
        $value = $this->validated('conversation_id');

        return $value !== null ? (int) $value : null;
    }

    public function isReconnect(): bool
    {
        return (bool) $this->boolean('reconnect');
    }
}
