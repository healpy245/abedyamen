<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use App\Enums\KamanSupport\SupportTicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::enum(SupportTicketStatus::class)],
            'assignee' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
