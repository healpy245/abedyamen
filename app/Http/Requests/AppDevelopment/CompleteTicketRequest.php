<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use Illuminate\Foundation\Http\FormRequest;

class CompleteTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('qaApprove', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
