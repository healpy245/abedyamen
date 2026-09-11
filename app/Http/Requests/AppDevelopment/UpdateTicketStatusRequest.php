<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('changeStatus', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AppDevelopmentTicketStatus::class)],
        ];
    }
}
