<?php

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChatbotInstanceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'system_prompt' => ['required', 'string'],
            'greenapi_url' => ['nullable', 'string', 'max:512', 'url'],
            'greenapi_webhook_url' => ['nullable', 'string', 'max:2000'],
            'whapi_webhook_url' => ['nullable', 'string', 'max:2000'],
            'allowed_reply_phones' => ['nullable', 'string', 'max:2000'],
            'whatsapp_provider' => ['sometimes', 'in:greenapi,whapi'],
            'whapi_token' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
