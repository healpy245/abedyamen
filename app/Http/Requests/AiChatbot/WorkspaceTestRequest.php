<?php

namespace App\Http\Requests\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('message') && trim((string) $this->input('message')) === '') {
            $this->merge(['message' => null]);
        }

        if ($this->boolean('reset')) {
            $this->merge(['reset' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required_without:reset', 'nullable', 'string', 'max:4000'],
            'channel' => ['nullable', 'string', Rule::in([
                ChatbotConversation::CHANNEL_WEB,
                ChatbotConversation::CHANNEL_WHATSAPP,
                ChatbotConversation::CHANNEL_VOICE,
                ChatbotConversation::CHANNEL_TEST,
            ])],
            'phone' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'identity_number' => ['nullable', 'string', 'max:32'],
            'conversation_id' => ['nullable', 'integer'],
            'reset' => ['sometimes', 'boolean'],
            'voice_mode' => ['sometimes', 'boolean'],
        ];
    }
}
