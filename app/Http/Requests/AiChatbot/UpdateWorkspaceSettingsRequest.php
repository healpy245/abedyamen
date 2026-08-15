<?php

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceSettingsRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'disabled_message' => ['nullable', 'string', 'max:1000'],
            'prompt_sections' => ['required', 'array'],
            'prompt_sections.identity' => ['nullable', 'array'],
            'prompt_sections.identity.bot_name' => ['nullable', 'string', 'max:120'],
            'prompt_sections.identity.company_name' => ['nullable', 'string', 'max:120'],
            'prompt_sections.identity.role' => ['nullable', 'string', 'max:500'],
            'prompt_sections.identity.languages' => ['nullable'],
            'prompt_sections.identity.dialect' => ['nullable', 'string', 'max:120'],
            'prompt_sections.identity.tone' => ['nullable', 'string', 'max:500'],
            'prompt_sections.business' => ['nullable', 'array'],
            'prompt_sections.business.*' => ['nullable', 'string', 'max:5000'],
            'prompt_sections.conversation_behavior' => ['nullable', 'array'],
            'prompt_sections.conversation_behavior.*' => ['nullable', 'string', 'max:5000'],
            'prompt_sections.restrictions' => ['nullable', 'array'],
            'prompt_sections.restrictions.*' => ['nullable', 'string', 'max:5000'],
            'prompt_sections.malan_workflows' => ['nullable', 'array'],
            'prompt_sections.malan_workflows.*' => ['nullable', 'string', 'max:5000'],
            'prompt_sections.advanced' => ['nullable', 'array'],
            'prompt_sections.advanced.custom_instructions' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
