<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAppDevelopmentAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp_notifications_enabled' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/[^\d+]/', '', (string) $this->input('phone', '')) ?? '';
        $password = $this->input('password');
        $this->merge([
            'phone' => $phone === '' ? null : $phone,
            'whatsapp_notifications_enabled' => $this->boolean('whatsapp_notifications_enabled'),
            'password' => is_string($password) && trim($password) === '' ? null : $password,
        ]);
    }
}
