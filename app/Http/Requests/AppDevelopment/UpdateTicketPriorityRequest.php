<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentTicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('changePriority', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
        ];
    }
}
