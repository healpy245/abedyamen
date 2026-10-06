<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use App\Enums\KamanSupport\SupportTicketEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketEventRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in([
                SupportTicketEventType::Note->value,
                SupportTicketEventType::Diagnosis->value,
                SupportTicketEventType::CommandResult->value,
            ])],
            'message' => ['nullable', 'string', 'max:8000'],
            'data' => ['nullable', 'array'],
        ];
    }
}
