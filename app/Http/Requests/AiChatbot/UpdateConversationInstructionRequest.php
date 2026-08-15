<?php

namespace App\Http\Requests\AiChatbot;

use App\Models\AiChatbot\ChatbotConversationInstruction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConversationInstructionRequest extends FormRequest
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
            'instruction' => ['sometimes', 'required', 'string', 'max:5000'],
            'scope' => ['sometimes', 'required', 'string', Rule::in([
                ChatbotConversationInstruction::SCOPE_NEXT_REPLY,
                ChatbotConversationInstruction::SCOPE_PERSISTENT,
                ChatbotConversationInstruction::SCOPE_REPLY_COUNT,
                ChatbotConversationInstruction::SCOPE_UNTIL_TIME,
            ])],
            'remaining_uses' => ['nullable', 'integer', 'min:1', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('instruction')) {
            $this->merge([
                'instruction' => strip_tags((string) $this->input('instruction')),
            ]);
        }
    }
}
