<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteRealtimeToolRequest extends FormRequest
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
            'call_id' => ['required', 'string'],
            'tool_name' => ['required', 'string', 'max:128'],
            'arguments' => ['nullable', 'array'],
            'call_id_openai' => ['nullable', 'string', 'max:128'],
        ];
    }
}
