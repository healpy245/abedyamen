<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('update', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AppDevelopmentTicketType::class)],
            'priority' => ['required', Rule::enum(AppDevelopmentTicketPriority::class)],
            'description' => ['required', 'string', 'max:20000'],
        ];
    }
}
