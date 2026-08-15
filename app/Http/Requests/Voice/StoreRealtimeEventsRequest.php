<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class StoreRealtimeEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1'],
            'events.*.type' => ['required', 'string', 'max:128'],
            'events.*.role' => ['nullable', 'string', 'max:32'],
            'events.*.content' => ['nullable', 'string', 'max:8000'],
            'events.*.metric_key' => ['nullable', 'string', 'max:64'],
            'events.*.payload' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ];
    }
}
