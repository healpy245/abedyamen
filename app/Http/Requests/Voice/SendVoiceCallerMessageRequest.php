<?php

namespace App\Http\Requests\Voice;

use Illuminate\Foundation\Http\FormRequest;

class SendVoiceCallerMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:10000'],
        ];
    }
}
