<?php

declare(strict_types=1);

namespace App\Http\Requests\KamanSupport;

use Illuminate\Foundation\Http\FormRequest;

class StorePosTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:2000'],
            'details' => ['nullable', 'string'],
            'clientTicketId' => ['nullable', 'string', 'max:64'],
            'createdAt' => ['nullable', 'string', 'max:64'],
            'restaurant' => ['nullable', 'string', 'max:120'],
            'restaurantName' => ['nullable', 'string', 'max:191'],
            'cashierName' => ['nullable', 'string', 'max:191'],
            'deviceId' => ['nullable', 'string', 'max:191'],
            'locale' => ['nullable', 'string', 'max:16'],
            'conversation' => ['nullable', 'array'],
            'conversation.*' => ['nullable'],
            'diagnosis' => ['nullable', 'array'],
            'printers' => ['nullable', 'array'],
            'printers.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->all();
    }
}
