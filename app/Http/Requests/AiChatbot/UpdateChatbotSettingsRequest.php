<?php

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChatbotSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (bool) $user->is_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $action = $this->input('action');

        if ($action === 'reset') {
            return [
                'action' => ['required', 'in:save,reset'],
            ];
        }

        return [
            'action' => ['required', 'in:save,reset'],
            'system_prompt' => ['required', 'string'],
            'chatbot_model' => ['required', 'string', 'max:255'],
            'temperature' => ['required', 'numeric', 'between:0,2'],
            'max_tokens' => ['required', 'integer', 'min:1', 'max:8000'],
            'typing_delay_enabled' => ['nullable', 'boolean'],
            'typing_reference_chars' => ['required', 'integer', 'min:10', 'max:2000'],
            'typing_reference_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'typing_min_seconds' => ['required', 'integer', 'min:0', 'max:120'],
            'typing_max_seconds' => ['required', 'integer', 'min:1', 'max:300'],
        ];
    }
}

