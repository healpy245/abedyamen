<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use App\Enums\KamanSupport\SupportCommandName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessProject('kaman-support') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::enum(SupportCommandName::class)],
            'args' => ['nullable', 'array'],
        ];
    }
}
