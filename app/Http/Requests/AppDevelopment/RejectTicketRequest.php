<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDevelopment;

use Illuminate\Foundation\Http\FormRequest;

class RejectTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket !== null && ($this->user()?->can('qaReject', $ticket) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:10000'],
        ];
    }
}
