<?php

namespace App\Http\Requests\AiChatbot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatbotMemberRequest extends FormRequest
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
        $instance = $this->route('instance');

        return [
            'name' => ['nullable', 'string', 'max:120'],
            'national_id' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('ai_chatbot_members', 'national_id')
                    ->where(fn ($query) => $query->where('instance_id', $instance?->id)),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'customer_type' => ['required', Rule::in(['new', 'subscriber'])],
            'payment_last4' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'router_type' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->filled('name') ? trim((string) $this->input('name')) : null,
            'national_id' => $this->filled('national_id') ? trim((string) $this->input('national_id')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'payment_last4' => $this->filled('payment_last4') ? trim((string) $this->input('payment_last4')) : null,
            'router_type' => $this->filled('router_type') ? trim((string) $this->input('router_type')) : null,
        ]);
    }
}
